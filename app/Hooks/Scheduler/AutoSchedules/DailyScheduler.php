<?php

namespace FluentCart\App\Hooks\Scheduler\AutoSchedules;


use FluentCart\App\Models\Cart;

class DailyScheduler
{

    private $startTimeStamp = null;

    public function register(): void
    {
        add_action('fluent_cart/scheduler/daily_tasks', [$this, 'handle']);
    }

    public function handle()
    {
        $this->startTimeStamp = time();
        $this->deleteOldCarts();
    }


    private function deleteOldCarts()
    {
        
        if ((time() - $this->startTimeStamp) > 40) {
            return; // Prevent long execution
        }

        $days = apply_filters('fluent_cart/cleanup/old_carts_days', 30);

        $carts = Cart::query()
            ->where('updated_at', '<=', date('Y-m-d H:i:s', strtotime('-' . $days . ' days')))
            ->limit(1000)
            ->get();

        if ($carts->isEmpty()) {
            return;
        }

        foreach ($carts as $cart) {
            $cart->delete();
        }

        // Recursively call to delete more carts
        $this->deleteOldCarts();
    }
}
