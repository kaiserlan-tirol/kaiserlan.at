<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserTransactionRepository;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class PizzaService
{
    public function __construct(
        private readonly CateringService $cateringService,
        private readonly SettingService $settingService,
        private readonly UserTransactionRepository $userTransactionRepository
    ) {
    }

    public function getPizzas(): array
    {
        $pizzas = [];
        $pizzaNames = [];
        $lines = preg_split('/\R/', (string) $this->settingService->get('pizza.list'));

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $parts = explode(';', $line);
            if (count($parts) !== 3) {
                continue;
            }

            [$name, $description, $price] = array_map('trim', $parts);
            $price = str_replace(',', '.', $price);
            if (!is_numeric($price)) {
                continue;
            }

            $price = (int) round((float) $price * 100);
            if ($price <= 0 || isset($pizzaNames[$name])) {
                continue;
            }

            $pizzaNames[$name] = true;
            $pizzas[$index] = [
                'name' => $name,
                'description' => $description,
                'price' => $price,
            ];
        }

        return $pizzas;
    }

    public function getDeadline(): ?\DateTimeImmutable
    {
        return $this->parseDateTimeSetting('pizza.order_open_until');
    }

    public function getOpenFrom(): ?\DateTimeImmutable
    {
        return $this->parseDateTimeSetting('lan.party.start');
    }

    public function isOrderingOpen(): bool
    {
        $openFrom = $this->getOpenFrom();
        $deadline = $this->getDeadline();
        $now = new \DateTimeImmutable();

        return $openFrom !== null
            && $deadline !== null
            && $this->getPizzas() !== []
            && $now >= $openFrom
            && $now <= $deadline;
    }

    public function getCurrentSelection(User $user): array
    {
        $openFrom = $this->getOpenFrom();
        $deadline = $this->getDeadline();
        if ($openFrom === null || $deadline === null) {
            return [];
        }

        $selection = [];
        $transactions = $this->userTransactionRepository->findCateringPizzaTransactions(
            $user->getUuid(),
            $openFrom,
            $deadline
        );

        foreach ($transactions as $transaction) {
            $description = $transaction->getDescription();

            if (str_starts_with($description, 'Storno Pizza: ')) {
                $description = substr($description, strlen('Storno Pizza: '));
                $multiplier = -1;
            } elseif (str_starts_with($description, 'Pizza: ')) {
                $description = substr($description, strlen('Pizza: '));
                $multiplier = 1;
            } else {
                continue;
            }

            [$name, $quantity] = $this->parseDescription($description);
            $selection[$name] ??= ['qty' => 0, 'owed' => 0];
            $selection[$name]['qty'] += $multiplier * $quantity;
            $selection[$name]['owed'] += $transaction->getAmount();
        }

        return array_filter($selection, static fn (array $item): bool => $item['qty'] > 0);
    }

    public function getSelectionByIndex(User $user): array
    {
        $selection = [];
        $currentSelection = $this->getCurrentSelection($user);

        foreach ($this->getPizzas() as $index => $pizza) {
            if (isset($currentSelection[$pizza['name']])) {
                $selection[$index] = $currentSelection[$pizza['name']]['qty'];
            }
        }

        return $selection;
    }

    public function bookOrder(User $user, array $qtyByIndex): void
    {
        if (!$this->isOrderingOpen()) {
            throw new BadRequestHttpException('Pizzabestellung ist derzeit nicht möglich.');
        }

        foreach ($this->getCurrentSelection($user) as $name => $selection) {
            $this->cateringService->addUserCredit(
                $user,
                abs($selection['owed']),
                $this->formatDescription('Storno Pizza: ', $name, $selection['qty'])
            );
        }

        $pizzas = $this->getPizzas();
        foreach ($qtyByIndex as $index => $quantity) {
            $quantity = (int) $quantity;
            if ($quantity <= 0 || !isset($pizzas[$index])) {
                continue;
            }

            $pizza = $pizzas[$index];
            $this->cateringService->deductUserCredit(
                $user,
                $quantity * $pizza['price'],
                null,
                $this->formatDescription('Pizza: ', $pizza['name'], $quantity)
            );
        }
    }

    private function parseDateTimeSetting(string $key): ?\DateTimeImmutable
    {
        $value = $this->settingService->get($key);
        if (!$value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function parseDescription(string $description): array
    {
        if (preg_match('/^(.*) \(×(\d+)\)$/u', $description, $matches)) {
            return [$matches[1], (int) $matches[2]];
        }

        return [$description, 1];
    }

    private function formatDescription(string $prefix, string $name, int $quantity): string
    {
        $suffix = $quantity > 1 ? " (×{$quantity})" : '';

        return $prefix . $name . $suffix;
    }
}
