<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\Meta;

class MetaFactory
{
    public function createVariantUpgradePath(int $fromVariantId, array $toVariants, bool $isProrate = false, int $discountAmount = 0): Meta
    {
        return Meta::query()->create([
            'object_id'   => $fromVariantId,
            'object_type' => 'variant_upgrade',
            'meta_key'    => 'variant_upgrade_path',
            'meta_value'  => [
                'to_variants'     => $toVariants,
                'is_prorate'      => $isProrate,
                'discount_amount' => $discountAmount,
            ],
        ]);
    }
}
