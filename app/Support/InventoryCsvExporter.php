<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BusinessProduct;
use App\Models\InventoryOrder;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams inventory data to CSV downloads. Kept in one place so the products
 * list and the order pages share the same export format.
 */
final class InventoryCsvExporter
{
    /**
     * Export products (the inventory data table) in the on-screen column order.
     *
     * @param  Builder<BusinessProduct>  $query
     */
    public function products(Builder $query): StreamedResponse
    {
        $headers = [
            'Name', 'Code', 'Category', 'Status Price', 'Description', 'In Stock',
            'Stock_warn limit', 'Size', 'Colour', 'Sku', 'Naira price',
        ];

        return $this->stream('inventory-' . now()->format('Ymd-His') . '.csv', $headers, function ($out) use ($query): void {
            $query->chunk(500, function ($products) use ($out): void {
                foreach ($products as $product) {
                    fputcsv($out, [
                        $product->name,
                        $product->code,
                        $product->category,
                        $product->is_active ? 'Active' : 'Inactive',
                        $product->description,
                        $product->stock_quantity,
                        $product->low_stock_threshold,
                        $product->size,
                        $product->colour,
                        $product->sku,
                        $product->retail_price,
                    ]);
                }
            });
        });
    }

    /**
     * Export orders (reference-level) with who created and checked them out.
     *
     * @param  Builder<InventoryOrder>  $query
     */
    public function orders(Builder $query): StreamedResponse
    {
        $headers = [
            'Reference', 'Customer', 'Type', 'Status', 'Total (Naira)',
            'Created by', 'Checked out by', 'Created at', 'Paid at',
        ];

        return $this->stream('inventory-orders-' . now()->format('Ymd-His') . '.csv', $headers, function ($out) use ($query): void {
            $query->with(['creator', 'paidBy'])->chunk(500, function ($orders) use ($out): void {
                foreach ($orders as $order) {
                    fputcsv($out, [
                        $order->reference,
                        $order->customer_name,
                        $order->price_type,
                        $order->status->label(),
                        $order->total_amount,
                        $order->creator?->name,
                        $order->paidBy?->name,
                        $order->created_at->format('Y-m-d H:i'),
                        $order->paid_at?->format('Y-m-d H:i'),
                    ]);
                }
            });
        });
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function stream(string $filename, array $headers, callable $writeRows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $writeRows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            $writeRows($out);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
