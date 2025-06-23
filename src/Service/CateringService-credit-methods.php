    /**
     * Get the user's credit balance
     * 
     * @param User|UuidInterface $user The user to get the credit for
     * @return int The user's credit balance in cents
     */
    public function getUserCredit(User|UuidInterface $user): int
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        $credit = $this->creditRepository->findByUser($uuid);
        
        return $credit ? $credit->getAmount() : 0;
    }

    /**
     * Add credit to a user's account
     * 
     * @param User|UuidInterface $user The user to add credit to
     * @param int $amount The amount to add in cents
     * @param string|null $note Optional note about the credit addition
     * @return UserCateringCredit The updated credit entity
     */
    public function addUserCredit(User|UuidInterface $user, int $amount, ?string $note = null): UserCateringCredit
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive');
        }
        
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        $credit = $this->creditRepository->findByUser($uuid);
        
        if (!$credit) {
            $credit = new UserCateringCredit();
            $credit->setUser($uuid);
        }
        
        $credit->addCredit($amount);
        if ($note) {
            $credit->setNote($note);
        }
        
        $this->creditRepository->save($credit);
        
        // Record transaction
        $transaction = new CateringCreditTransaction();
        $transaction->setUser($uuid);
        $transaction->setAmount($amount);
        $transaction->setType(CateringCreditTransaction::TYPE_PAYMENT_RECEIVED);
        $transaction->setDescription($note ?? 'Guthaben aufgeladen');
        
        $this->transactionRepository->save($transaction);
        
        return $credit;
    }
    
    /**
     * Deduct credit from a user's account
     * 
     * @param User|UuidInterface $user The user to deduct credit from
     * @param int $amount The amount to deduct in cents
     * @param CateringOrder|null $order Associated order if applicable
     * @return bool True if enough credit was available and deducted, false otherwise
     */
    public function deductUserCredit(User|UuidInterface $user, int $amount, ?CateringOrder $order = null): bool
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Deduction amount must be positive');
        }
        
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        $credit = $this->creditRepository->findByUser($uuid);
        
        if (!$credit || $credit->getAmount() < $amount) {
            return false;
        }
        
        $credit->deductCredit($amount);
        $this->creditRepository->save($credit);
        
        // Record transaction
        $transaction = new CateringCreditTransaction();
        $transaction->setUser($uuid);
        $transaction->setAmount(-$amount);
        $transaction->setType(CateringCreditTransaction::TYPE_ORDER_PAYMENT);
        
        if ($order) {
            $transaction->setOrder($order);
            $transaction->setDescription('Bezahlung für Bestellung #' . $order->getId());
        } else {
            $transaction->setDescription('Guthaben verwendet');
        }
        
        $this->transactionRepository->save($transaction);
        
        return true;
    }
    
    /**
     * Get transaction history for a user
     * 
     * @param User|UuidInterface $user The user to get transaction history for
     * @return array The transaction history
     */
    public function getUserTransactionHistory(User|UuidInterface $user): array
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        return $this->transactionRepository->findByUser($uuid);
    }

    /**
     * Mark orders as payment sent by the user
     * 
     * @param array $orders The orders to mark
     */
    public function markOrdersAsPaymentSent(array $orders): void
    {
        foreach ($orders as $order) {
            if ($order->isOpen()) {
                $this->setState($order, CateringOrderStatus::PaymentSent);
            }
        }
        $this->em->flush();
    }
    
    /**
     * Process a payment from a user
     * 
     * This will:
     * 1. Mark as many orders as paid as the payment amount covers
     * 2. Add any remaining amount as credit to the user's account
     * 
     * @param User|UuidInterface $user The user who made the payment
     * @param int $amount The payment amount in cents
     * @param string|null $note Optional note about the payment
     * @return array Summary of the payment processing
     */
    public function processPayment(User|UuidInterface $user, int $amount, ?string $note = null): array
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        $ordersProcessed = 0;
        $amountUsed = 0;
        $amountToCredit = 0;
        
        // Get all orders that are marked as payment sent, sorted by creation date (oldest first)
        $paymentSentOrders = $this->orderRepository->findBy(
            ['orderer' => $uuid, 'status' => CateringOrderStatus::PaymentSent],
            ['createdAt' => 'ASC']
        );
        
        // If no orders marked as payment sent, also try to cover regular open orders
        if (empty($paymentSentOrders)) {
            $paymentSentOrders = $this->orderRepository->findBy(
                ['orderer' => $uuid, 'status' => CateringOrderStatus::Created],
                ['createdAt' => 'ASC']
            );
        }
        
        // Mark orders as paid until we run out of payment
        $remainingAmount = $amount;
        foreach ($paymentSentOrders as $order) {
            $orderTotal = $order->calculateTotal();
            
            // If we have enough payment left to cover this order
            if ($remainingAmount >= $orderTotal) {
                $this->setState($order, CateringOrderStatus::Paid);
                $remainingAmount -= $orderTotal;
                $amountUsed += $orderTotal;
                $ordersProcessed++;
                
                // Record transaction for this order
                $transaction = new CateringCreditTransaction();
                $transaction->setUser($uuid);
                $transaction->setAmount(-$orderTotal);
                $transaction->setType(CateringCreditTransaction::TYPE_ORDER_PAYMENT);
                $transaction->setOrder($order);
                $transaction->setDescription('Bezahlung für Bestellung #' . $order->getId());
                $this->transactionRepository->save($transaction);
            } else {
                // Not enough to cover this order - it remains in payment_sent status
                break;
            }
        }
        
        // Add any remaining amount as credit
        if ($remainingAmount > 0) {
            $this->addUserCredit($uuid, $remainingAmount, $note);
            $amountToCredit = $remainingAmount;
        }
        
        $this->em->flush();
        
        return [
            'orders_processed' => $ordersProcessed,
            'amount_used' => $amountUsed,
            'amount_credited' => $amountToCredit,
        ];
    }
    
    /**
     * Apply a user's available credit to their open orders
     * 
     * @param User|UuidInterface $user The user
     * @return array Summary of credit application
     */
    public function applyUserCreditToOrders(User|UuidInterface $user): array
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        $availableCredit = $this->getUserCredit($user);
        $ordersProcessed = 0;
        $amountUsed = 0;
        
        if ($availableCredit <= 0) {
            return [
                'orders_processed' => 0,
                'amount_used' => 0,
                'remaining_credit' => 0,
            ];
        }
        
        // Get open orders, sorted by creation date (oldest first)
        $openOrders = $this->orderRepository->findBy(
            ['orderer' => $uuid, 'status' => CateringOrderStatus::Created],
            ['createdAt' => 'ASC']
        );
        
        // Apply credit to orders
        $remainingCredit = $availableCredit;
        foreach ($openOrders as $order) {
            $orderTotal = $order->calculateTotal();
            
            // If we have enough credit to cover this order
            if ($remainingCredit >= $orderTotal) {
                $this->setState($order, CateringOrderStatus::Paid);
                $this->deductUserCredit($uuid, $orderTotal, $order);
                $remainingCredit -= $orderTotal;
                $amountUsed += $orderTotal;
                $ordersProcessed++;
            } else {
                // Not enough credit to cover this order
                break;
            }
        }
        
        return [
            'orders_processed' => $ordersProcessed,
            'amount_used' => $amountUsed,
            'remaining_credit' => $remainingCredit,
        ];
    }
