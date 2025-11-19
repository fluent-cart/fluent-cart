<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\Models\Cart;
use FluentCart\App\Services\Report\Concerns\HasRange;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\DateTime;

class CartReportService extends ReportService
{
    use HasRange;

    protected $idleCartCount = 0;
    protected $abandonedCartCount = 0;
    protected $totalAbandonedCartTotal = 0;
    protected $averageAbandonedCartTotal = 0;
    protected $totalAbandonedItems = 0;
    protected $averageAbandonedItems = 0;

    public function getModel(): string
    {
        return Cart::class;
    }

    protected function modifyQuery(Builder $query): Builder
    {
        return $query;
    }

    protected function prepareReportData(): void
    {
        $this->idleCartCount = 0;
        $this->abandonedCartCount = 0;
        $this->totalAbandonedCartTotal = 0;
        $this->averageAbandonedCartTotal = 0;
        $this->totalAbandonedItems = 0;
        $this->averageAbandonedItems = 0;

        $currentTime = new DateTime();

        $this->data->each(function ($cart) use ($currentTime) {
            $lastUpdatedTime = new DateTime($cart->updated_at);
            $timeDifference = $currentTime->diff($lastUpdatedTime);

            // Calculate the difference in minutes
            $minutesDifference = ($timeDifference->days * 24 * 60) + ($timeDifference->h * 60) + $timeDifference->i;

            // Check if the cart is idle or abandoned
            if ($minutesDifference > 5 && $minutesDifference <= 10) {
                $this->idleCartCount++;
            } elseif ($minutesDifference > 10) {
                $this->abandonedCartCount++;

                // Calculate totals for abandoned carts
                $cartTotal = $cart->total ?? 0; // Assuming cart has a total field
                $this->totalAbandonedCartTotal += $cartTotal;

                $abandonedItemsCount = count($cart->cart_data);
                $this->totalAbandonedItems += $abandonedItemsCount;
            }
        });

        // Calculate averages
        $this->averageAbandonedCartTotal = $this->abandonedCartCount > 0
            ? $this->totalAbandonedCartTotal / $this->abandonedCartCount
            : 0;

        $this->averageAbandonedItems = $this->abandonedCartCount > 0
            ? $this->totalAbandonedItems / $this->abandonedCartCount
            : 0;
    }


    public function getAbandonedCartItems()
    {
        $abandonedItems = [];

        $currentTime = new DateTime();

        $this->data->each(function ($cart) use ($currentTime, &$abandonedItems) {
            $lastUpdatedTime = new DateTime($cart->updated_at);
            $timeDifference = $currentTime->diff($lastUpdatedTime);

            // Calculate the difference in seconds
            $secondsDifference = ($timeDifference->days * 24 * 60 * 60) + ($timeDifference->h * 60 * 60) + ($timeDifference->i * 60) + $timeDifference->s;

            // Check if the cart is idle or abandoned
            if ($secondsDifference >= 30 && $secondsDifference <= 60) {
                // Mark as idle (if needed, you can add additional idle cart logic here)
            } elseif ($secondsDifference > 60) {
                // Mark as abandoned
                foreach ($cart->cart_data as $item) {
                    $productName = $item['title'];
                    $unitPrice = $item['price'];

                    // Check if the product is already in the abandoned items list
                    if (!isset($abandonedItems[$productName])) {
                        $abandonedItems[$productName] = [
                            'product_name' => $productName,
                            'abandoned_times' => 0,
                            'unit_price' => $unitPrice,
                        ];
                    }

                    // Increment the abandoned times for the product
                    $abandonedItems[$productName]['abandoned_times']++;
                }
            }
        });

        return [
            'abandonedItems' => array_values($abandonedItems)
        ]; // Reset array keys for cleaner output
    }

    public function getSummary()
    {
        return [
            'idleCartCount' => $this->idleCartCount,
            'abandonedCartCount' => $this->abandonedCartCount,
            'totalAbandonedCartTotal' => $this->totalAbandonedCartTotal,
            'averageAbandonedCartTotal' => $this->averageAbandonedCartTotal,
            'totalAbandonedItems' => $this->totalAbandonedItems,
            'averageAbandonedItems' => $this->averageAbandonedItems,
        ];
    }

}