<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The company dashboard asks every module for its widget. A module listens, checks that it is active for the company and that the user
 * may see it, and calls add(). Widgets are plain data, the page renders them all the same way:
 *
 *   ['key' => 'hrm', 'title' => 'HRM', 'href' => '/hrm/dashboard', 'order' => 10,
 *    'stats' => [['label' => 'Present today', 'value' => 12, 'format' => 'number'|'money', 'hint' => '3 on leave']]]
 */
class CollectDashboardWidgets
{
    use Dispatchable;

    /** @var array<int, array<string, mixed>> */
    public array $widgets = [];

    public function __construct(public User $user)
    {
    }

    /** @param array<string, mixed> $widget */
    public function add(array $widget): void
    {
        $this->widgets[] = $widget + ['order' => 50, 'href' => null];
    }
}
