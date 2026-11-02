<?php

declare(strict_types=1);

namespace Marketplace\Orders\Service;

use Marketplace\Commission\Service\CommissionCalculator;
use Marketplace\Optimizer\Dto\OptimizationResult;
use Marketplace\Orders\Dto\SplitOrderPlan;
use Marketplace\Orders\Dto\VendorOrderDraft;

/**
 * Turns one optimized cart into a parent marketplace order and one sub-order per vendor.
 * References look like 1001 and 1001-A.
 */
final class OrderSplitter
{
    public function __construct(private readonly CommissionCalculator $commissions)
    {
    }

    /**
     * @param array<int, string> $commissionPercentByVendorId
     */
    public function split(
        OptimizationResult $result,
        string $postcode,
        string $parentReference,
        array $commissionPercentByVendorId,
    ): SplitOrderPlan {
        if (!preg_match('/^\d+$/', $parentReference)) {
            throw new \InvalidArgumentException('Parent reference must be numeric.');
        }
        if ($result->vendors === []) {
            throw new \InvalidArgumentException('Cannot split an empty allocation.');
        }

        $drafts = [];
        foreach ($result->vendors as $vendor) {
            $percent = $commissionPercentByVendorId[$vendor->vendorId] ?? null;
            if ($percent === null) {
                throw new \InvalidArgumentException('Missing commission rate for vendor ' . $vendor->vendorId);
            }
            $commission = $this->commissions->calculate($vendor->productSubtotalCents, $percent);
            $drafts[] = new VendorOrderDraft(
                $parentReference . '-' . $vendor->vendorCode,
                $vendor->vendorId,
                $vendor->vendorCode,
                $vendor->vendorName,
                $vendor->productSubtotalCents,
                $vendor->shippingCents,
                $vendor->grandTotalCents(),
                $commission->percent,
                $commission->commissionCents,
                $commission->netCents,
                $vendor->lines,
            );
        }

        return new SplitOrderPlan(
            $parentReference,
            $postcode,
            $result->status->value,
            $result->productSubtotalCents,
            $result->shippingTotalCents,
            $result->grandTotalCents,
            $drafts,
        );
    }
}
