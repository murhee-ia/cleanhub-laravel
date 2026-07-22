<?php

namespace App\Enums;

enum JobPostVisibility: string
{
    case Draft = 'draft';
    case Published = 'published';
}
