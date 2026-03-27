<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * NewsletterSend Model
 * Tracks admin-sent newsletters.

 */
class NewsletterSend extends Model
{
    protected $table            = 'newsletter_sends';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id', 'subject', 'bodyHtml', 'recipientGroup',
        'totalCount', 'sentCount', 'failedCount', 'status',
        'sentBy', 'sentAt', 'createdAt', 'updatedAt',
    ];

    protected $useTimestamps = false;
}
