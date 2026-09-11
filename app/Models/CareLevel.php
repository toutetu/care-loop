<?php

namespace App\Models;

use Database\Factories\CareLevelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 要介護度マスタ。
 *
 * residents から名称を分離した先（第3正規形）。名称が変わってもマスタ1行の
 * 更新で済み、利用者側の更新漏れ（更新不整合）が起きない。
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $sort_order
 */
#[Fillable(['code', 'name', 'sort_order'])]
class CareLevel extends Model
{
    /** @use HasFactory<CareLevelFactory> */
    use HasFactory;

    /**
     * @return HasMany<Resident, $this>
     */
    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class);
    }
}
