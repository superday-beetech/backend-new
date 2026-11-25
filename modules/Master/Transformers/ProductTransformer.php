<?php

namespace Modules\Master\Transformers;

use App\Core\Transformers\BaseTransformer;

class ProductTransformer extends BaseTransformer
{
    public function transform(array|object $item): array
    {
        return [
            'id'             => $this->toInt($this->get($item, 'id')),
            'sku'            => $this->toString($this->get($item, 'sku')),
            'barcode'        => $this->toString($this->get($item, 'barcode')),
            'name'           => $this->toString($this->get($item, 'name')),
            'category_id'    => $this->toInt($this->get($item, 'category_id')),
            'unit_id'        => $this->toInt($this->get($item, 'unit_id')),
            'purchase_price' => $this->toFloat($this->get($item, 'purchase_price')),
            'selling_price'  => $this->toFloat($this->get($item, 'selling_price')),
            'is_active'      => $this->toBool($this->get($item, 'is_active')),
            'created_at'     => $this->toDate($this->get($item, 'created_at')),
            'updated_at'     => $this->toDate($this->get($item, 'updated_at')),
        ];
    }
}
