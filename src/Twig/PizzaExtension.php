<?php

namespace App\Twig;

use App\Service\PizzaService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PizzaExtension extends AbstractExtension
{
    public function __construct(private readonly PizzaService $pizzaService)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pizza_ordering_open', $this->pizzaService->isOrderingOpen(...)),
            new TwigFunction('pizza_deadline', $this->pizzaService->getDeadline(...)),
        ];
    }
}
