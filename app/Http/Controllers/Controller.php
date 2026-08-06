<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Shared validation for the client-selectable page size on every paginated
     * list endpoint. Out-of-range values are rejected rather than clamped, so a
     * client always knows the page size it asked for is the one it got.
     *
     * @return array<int, string>
     */
    protected function perPageRule(): array
    {
        return ['sometimes', 'integer', 'min:50', 'max:200'];
    }
}
