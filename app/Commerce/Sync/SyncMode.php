<?php

namespace App\Commerce\Sync;

enum SyncMode: string
{
    case Full = 'full';
    case Delta = 'delta';
}
