<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use App\Models\User;

class UserSeeder extends Seeder
{
    public function run()
    {
        $userModel = new User();
        $db = \Config\Database::connect();
        
        // Generate secure password
        $plainPassword = 'Fortune@2024#Admin!';
        $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
        
        // Check if user already exists
        $existing = $db->table('users')->where('email', 'adusoftjeff@gmail.com')->get()->getRowArray();
        
        $now = date('Y-m-d H:i:s');
        
        $userData = [
            'id' => uniqid('user_'),
            'email' => 'adusoftjeff@gmail.com',
            'password' => $hashedPassword,
            'firstName' => 'Jeff',
            'lastName' => 'Adusoft',
            'role' => 'SUPER_ADMIN',
            'status' => 'ACTIVE',
            'phone' => null,
            'avatar' => null,
            'emailVerified' => true,
            'emailVerifiedAt' => $now,
            'resetPasswordToken' => null,
            'resetPasswordExpires' => null,
            'lastLoginAt' => null,
            'lastLoginIp' => null,
            'createdAt' => $now,
            'updatedAt' => $now
        ];
        
        if ($existing) {
            // Update existing user (but keep the original ID)
            unset($userData['id']);
            $userData['updatedAt'] = $now;
            $userData['password'] = $hashedPassword; // Update password
            
            $db->table('users')->where('email', 'adusoftjeff@gmail.com')->update($userData);
            echo "✅ Updated user: adusoftjeff@gmail.com\n";
        } else {
            // Insert new user
            $db->table('users')->insert($userData);
            echo "✅ Created user: adusoftjeff@gmail.com\n";
        }
        
        // Display login credentials
        echo "\n";
        echo "═══════════════════════════════════════════════════════════\n";
        echo "  USER LOGIN CREDENTIALS\n";
        echo "═══════════════════════════════════════════════════════════\n";
        echo "  Email:    adusoftjeff@gmail.com\n";
        echo "  Password: Fortune@2024#Admin!\n";
        echo "  Role:     SUPER_ADMIN\n";
        echo "  Status:   ACTIVE\n";
        echo "═══════════════════════════════════════════════════════════\n";
        echo "\n";
        echo "⚠️  IMPORTANT: Save these credentials securely!\n";
        echo "\n";
    }
}
