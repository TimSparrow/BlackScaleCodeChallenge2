<?php

namespace App\Providers\Email;

/**
 *
 */
interface RandomEmailInterface
{
    public function getEmail(): string;

    public function findCode(): string;
}
