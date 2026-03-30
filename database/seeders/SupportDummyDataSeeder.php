<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SupportAndConnect\Ticket\SupportTicket;
use App\Models\SupportAndConnect\Contacts\ContactMessage;

class SupportDummyDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create Support Tickets
        $t1 = SupportTicket::create([
            'user_id' => 1,
            'subject' => 'Payment Failed for Order #ORD-12345',
            'message' => 'I tried to pay using my Visa card but it got declined twice. Please help!',
            'status' => 'open',
            'priority' => 'high'
        ]);

        $t2 = SupportTicket::create([
            'user_id' => 1,
            'subject' => 'Delivery Delay Concern',
            'message' => 'My order was supposed to arrive yesterday. Any updates?',
            'status' => 'pending',
            'priority' => 'medium'
        ]);

        $t3 = SupportTicket::create([
            'user_id' => 1,
            'subject' => 'Product Feature Request',
            'message' => 'Would love to see Dark Mode on the mobile app!',
            'status' => 'closed',
            'priority' => 'low'
        ]);

        // 2. Add Replies for Ticket 1
        $t1->replies()->create([
            'admin_id' => 1,
            'reply' => 'Hello! We are looking into your payment issue. Please wait while we verify with the gateway.'
        ]);

        $t1->replies()->create([
            'user_id' => 1,
            'reply' => 'Thank you for the quick response. Waiting for updates.'
        ]);

        // 3. Add Contact Messages
        ContactMessage::create([
            'full_name' => 'John Doe',
            'email' => 'john@example.com',
            'subject' => 'Wholesale Inquiry',
            'message' => 'Interested in buying 500 units of the premium sneakers. Do you offer discounts?',
            'email_sent' => true
        ]);

        ContactMessage::create([
            'full_name' => 'Jane Smith',
            'email' => 'jane@test.com',
            'subject' => 'Partnership Proposal',
            'message' => 'I have a tech review channel with 10k subscribers. Let\'s collaborate!',
            'email_sent' => true
        ]);

        ContactMessage::create([
            'full_name' => 'Michael Brown',
            'email' => 'michael@corp.com',
            'subject' => 'Custom Logo Design',
            'message' => 'Do you provide custom branding for corporate orders?',
            'email_sent' => true
        ]);
    }
}
