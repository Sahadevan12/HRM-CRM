<?php

namespace Workdo\SalesPurchase\Support;

use InvalidArgumentException;

/**
 * Describes the five trade documents that share the `documents` table.
 *
 * stock: what happens to stock at the "stock moment" (post / approve):
 *   'out' = leaves the warehouse, 'in' = enters the warehouse, null = no stock effect (proposals)
 */
final class DocumentType
{
    public const SALES_INVOICE = 'sales_invoice';
    public const PURCHASE_INVOICE = 'purchase_invoice';
    public const SALES_PROPOSAL = 'sales_proposal';
    public const SALES_RETURN = 'sales_return';
    public const PURCHASE_RETURN = 'purchase_return';

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            self::SALES_PROPOSAL => [
                'label' => 'Sales Proposal', 'plural' => 'Sales Proposals', 'slug' => 'sales-proposals', 'prefix' => 'SP',
                'party' => 'client', 'party_label' => 'Customer', 'stock' => null, 'is_return' => false,
                'statuses' => ['draft', 'sent', 'accepted', 'rejected', 'converted'],
            ],
            self::SALES_INVOICE => [
                'label' => 'Sales Invoice', 'plural' => 'Sales Invoices', 'slug' => 'sales-invoices', 'prefix' => 'SI',
                'party' => 'client', 'party_label' => 'Customer', 'stock' => 'out', 'is_return' => false,
                'statuses' => ['draft', 'posted'],
            ],
            self::PURCHASE_INVOICE => [
                'label' => 'Purchase Invoice', 'plural' => 'Purchase Invoices', 'slug' => 'purchase-invoices', 'prefix' => 'PI',
                'party' => 'vendor', 'party_label' => 'Vendor', 'stock' => 'in', 'is_return' => false,
                'statuses' => ['draft', 'posted'],
            ],
            self::SALES_RETURN => [
                'label' => 'Sales Return', 'plural' => 'Sales Returns', 'slug' => 'sales-returns', 'prefix' => 'SR',
                'party' => 'client', 'party_label' => 'Customer', 'stock' => 'in', 'is_return' => true,
                'parent_type' => self::SALES_INVOICE, 'statuses' => ['draft', 'approved', 'completed'],
            ],
            self::PURCHASE_RETURN => [
                'label' => 'Purchase Return', 'plural' => 'Purchase Returns', 'slug' => 'purchase-returns', 'prefix' => 'PR',
                'party' => 'vendor', 'party_label' => 'Vendor', 'stock' => 'out', 'is_return' => true,
                'parent_type' => self::PURCHASE_INVOICE, 'statuses' => ['draft', 'approved', 'completed'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function get(string $type): array
    {
        return self::all()[$type] ?? throw new InvalidArgumentException("Unknown document type '{$type}'.");
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function isReturn(string $type): bool
    {
        return (bool) self::get($type)['is_return'];
    }
}
