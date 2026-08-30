<?php

namespace App\Filters;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class NickFilter
{
    protected $request;
    protected $builder;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function apply(Builder $builder)
    {
        $this->builder = $builder;


        if ($this->request->filled('search')) {
            $search = $this->request->search;

            $this->builder->where(function ($query) use ($search) {
                $query->where('account_name', 'LIKE', '%' . $search . '%');

                // Nếu search là số, thì tìm theo id luôn
                if (is_numeric($search)) {
                    $query->orWhere('id', $search);
                }
                $query->orWhereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('username', 'LIKE', '%' . $search . '%');
                });
            });
        }
        // Trong NickFilter.php
        if ($this->request->filled('date_from')) {
            $this->builder->whereDate('created_at', '>=', $this->request->date_from);
        }

        if ($this->request->filled('date_to')) {
            $this->builder->whereDate('created_at', '<=', $this->request->date_to);
        }
        // Lọc theo Category
        if ($this->request->filled('category_id')) {
            $this->builder->where('category_id', $this->request->category_id);
        }

        // Lọc theo GameType thông qua Category
        if ($this->request->filled('game_type_id')) {
            $this->builder->whereHas('category', function ($q) {
                $q->where('game_type_id', $this->request->game_type_id);
            });
        }

        // Lọc theo Status
        if ($this->request->filled('status')) {
            $this->builder->where('status', $this->request->status);
        }

        // ✅ Lọc theo User — chỉ Admin được phép lọc
        // Bạn nên gán Policy trong Controller trước khi gọi Filter này!
        if ($this->request->filled('user_id')) {
            $this->builder->where('user_id', $this->request->user_id);
        }
        // Bổ sung: Lọc theo thuộc tính cụ thể
        // if ($this->request->filled('attribute_id') && $this->request->filled('attribute_option_id')) {
        //     $this->builder->whereHas('attributes', function ($q) {
        //         $q->where('attributes.id', $this->request->attribute_id)
        //             ->wherePivot('attribute_option_id', $this->request->attribute_option_id);
        //     });
        // }

        return $this->builder;
    }
}
