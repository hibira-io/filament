<?php

namespace Hibira\Filament\Tests\Fixtures;

use Illuminate\Notifications\Notification;

/** A plain Laravel notification stored with the database channel. */
class OrderShipped extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['title' => 'Order shipped', 'format' => 'filament'];
    }
}
