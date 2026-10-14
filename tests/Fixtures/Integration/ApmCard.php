<?php

declare(strict_types=1);

namespace Fledge\Apm\Tests\Fixtures\Integration;

use Livewire\Component;

/**
 * A lazy-dashboard-style Livewire card: loads its data while rendering.
 */
final class ApmCard extends Component
{
    public int $card = 0;

    public int $rows = 0;

    public function mount(int $card): void
    {
        $this->card = $card;
    }

    public function render(): string
    {
        $this->rows = ParallelCards::work($this->card)['rows'];

        return '<div>{{ $rows }}</div>';
    }
}
