<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use App\Models\Service;

class ServiceSeeder extends Seeder
{
    public function run()
    {
        $serviceModel = new Service();
        $db = \Config\Database::connect();

        // Clear existing services (optional - comment out if you want to keep existing data)
        // $serviceModel->truncate();

        $services = [
            [
                'id' => 'service_' . uniqid(),
                'title' => 'Payroll Management',
                'slug' => 'payroll',
                'description' => 'Comprehensive payroll processing with automated tax calculations, KRA compliance, and employee self-service portals.',
                'shortDesc' => 'Automated payroll with KRA compliance',
                'icon' => 'Shield',
                'color' => 'bg-primary-100 text-primary-600 dark:bg-primary-900/20 dark:text-primary-400',
                'category' => 'HR Solutions',
                'features' => json_encode([
                    'Automated Calculations',
                    'KRA Tax Compliance',
                    'NSSF & NHIF Integration',
                    'Employee Self-Service',
                ]),
                'processSteps' => json_encode([
                    [
                        'step' => '01',
                        'title' => 'Data Collection',
                        'description' => 'Gather employee data, attendance, and time records automatically.',
                    ],
                    [
                        'step' => '02',
                        'title' => 'Calculation Engine',
                        'description' => 'Process salaries, taxes, deductions with our advanced algorithms.',
                    ],
                    [
                        'step' => '03',
                        'title' => 'Compliance Check',
                        'description' => 'Verify all calculations meet regulatory requirements.',
                    ],
                    [
                        'step' => '04',
                        'title' => 'Disbursement',
                        'description' => 'Generate payslips and process payments securely.',
                    ],
                ]),
                'complianceItems' => json_encode([
                    [
                        'title' => 'KRA Tax Compliance & PAYE Calculations',
                        'description' => 'Automated tax calculations',
                    ],
                    [
                        'title' => 'NSSF & NHIF Automatic Deductions',
                        'description' => 'Statutory deductions',
                    ],
                    [
                        'title' => 'Statutory Returns & Reporting',
                        'description' => 'Compliance reporting',
                    ],
                    [
                        'title' => 'Regular Updates for Law Changes',
                        'description' => 'Stay updated',
                    ],
                ]),
                'onQuote' => true,
                'hasProcess' => true,
                'hasCompliance' => true,
                'isActive' => true,
                'isFeatured' => true,
                'isPopular' => true,
                'position' => 1,
                'buttonText' => 'Get Started',
                'buttonLink' => '/services/payroll',
            ],
            [
                'id' => 'service_' . uniqid(),
                'title' => 'Recruitment Services',
                'slug' => 'recruitment',
                'description' => 'Professional recruitment and headhunting services for local and international companies.',
                'shortDesc' => 'Professional talent acquisition',
                'icon' => 'Users',
                'color' => 'bg-orange-100 text-orange-600 dark:bg-orange-900/20 dark:text-orange-400',
                'category' => 'HR Solutions',
                'features' => json_encode([
                    'Executive Search',
                    'Bulk Recruitment',
                    'Skills Assessment',
                    'Background Verification',
                ]),
                'processSteps' => null,
                'complianceItems' => null,
                'onQuote' => true,
                'hasProcess' => false,
                'hasCompliance' => false,
                'isActive' => true,
                'isFeatured' => true,
                'isPopular' => false,
                'position' => 2,
                'buttonText' => 'Start Hiring',
                'buttonLink' => '/services/recruitment',
            ],
            [
                'id' => 'service_' . uniqid(),
                'title' => 'AI Solutions',
                'slug' => 'ai-solutions',
                'description' => 'Cutting-edge AI solutions for various business needs, including chatbots, predictive analytics, and process automation.',
                'shortDesc' => 'Innovative AI solutions',
                'icon' => 'Robot',
                'color' => 'bg-purple-100 text-purple-600 dark:bg-purple-900/20 dark:text-purple-400',
                'category' => 'HR Solutions',
                'features' => json_encode([
                    'Predictive Analytics',
                    'Process Automation',
                    'Chatbots',
                    'Custom AI Models',
                ]),
                'processSteps' => null,
                'complianceItems' => null,
                'onQuote' => true,
                'hasProcess' => false,
                'hasCompliance' => false,
                'isActive' => true,
                'isFeatured' => true,
                'isPopular' => false,
                'position' => 3,
                'buttonText' => 'Learn More',
                'buttonLink' => '/services/ai-solutions',
            ],
            [
                'id' => 'service_' . uniqid(),
                'title' => 'Time & Attendance',
                'slug' => 'attendance',
                'description' => 'Smart time tracking with automated scheduling, biometric integration, and comprehensive attendance management.',
                'shortDesc' => 'Smart time tracking solutions',
                'icon' => 'Clock',
                'color' => 'bg-blue-100 text-blue-600 dark:bg-blue-900/20 dark:text-blue-400',
                'category' => 'HR Solutions',
                'features' => json_encode([
                    'Biometric Integration',
                    'Mobile Clock-in',
                    'Shift Management',
                    'Overtime Tracking',
                ]),
                'processSteps' => null,
                'complianceItems' => null,
                'onQuote' => true,
                'hasProcess' => false,
                'hasCompliance' => false,
                'isActive' => true,
                'isFeatured' => false,
                'isPopular' => false,
                'position' => 4,
                'buttonText' => 'Learn More',
                'buttonLink' => '/services/attendance',
            ],
            [
                'id' => 'service_' . uniqid(),
                'title' => 'Staff Outsourcing',
                'slug' => 'outsourcing',
                'description' => 'Complete staff outsourcing solutions allowing you to focus on core business.',
                'shortDesc' => 'Complete HR outsourcing',
                'icon' => 'Globe',
                'color' => 'bg-green-100 text-green-600 dark:bg-green-900/20 dark:text-green-400',
                'category' => 'HR Solutions',
                'features' => json_encode([
                    'Full HR Management',
                    'Payroll Processing',
                    'Compliance Handling',
                    'Employee Benefits',
                ]),
                'processSteps' => null,
                'complianceItems' => null,
                'onQuote' => true,
                'hasProcess' => false,
                'hasCompliance' => false,
                'isActive' => true,
                'isFeatured' => false,
                'isPopular' => false,
                'position' => 5,
                'buttonText' => 'Learn More',
                'buttonLink' => '/services/outsourcing',
            ],
            [
                'id' => 'service_' . uniqid(),
                'title' => 'HR Consulting',
                'slug' => 'hr-consulting',
                'description' => 'Expert HR consulting services to optimize human resource strategies and improve organizational performance.',
                'shortDesc' => 'Strategic HR consulting',
                'icon' => 'TrendingUp',
                'color' => 'bg-purple-100 text-purple-600 dark:bg-purple-900/20 dark:text-purple-400',
                'category' => 'Consulting',
                'features' => json_encode([
                    'Policy Development',
                    'Process Optimization',
                    'Training Programs',
                    'Performance Management',
                ]),
                'processSteps' => null,
                'complianceItems' => null,
                'onQuote' => true,
                'hasProcess' => false,
                'hasCompliance' => false,
                'isActive' => true,
                'isFeatured' => true,
                'isPopular' => false,
                'position' => 6,
                'buttonText' => 'Learn More',
                'buttonLink' => '/technology/hr-system',
            ],
            [
                'id' => 'service_' . uniqid(),
                'title' => 'HR System',
                'slug' => 'hr-system',
                'description' => 'Cloud-based HR platform centralizing all human resource operations with workflow automation and custom reports.',
                'shortDesc' => 'Complete HR management platform',
                'icon' => 'Monitor',
                'color' => 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/20 dark:text-indigo-400',
                'category' => 'Technology',
                'features' => json_encode([
                    'Employee Database',
                    'Document Management',
                    'Workflow Automation',
                    'Custom Reports',
                ]),
                'processSteps' => null,
                'complianceItems' => null,
                'onQuote' => false,
                'hasProcess' => false,
                'hasCompliance' => false,
                'isActive' => true,
                'isFeatured' => false,
                'isPopular' => false,
                'position' => 7,
                'buttonText' => 'Get Demo',
                'buttonLink' => '/contact',
            ],
        ];

        // Insert services using database builder directly to avoid timestamp issues
        foreach ($services as $service) {
            // Check if service with this slug already exists
            $existing = $db->table('services')->where('slug', $service['slug'])->get()->getRowArray();

            // Set timestamps - try both camelCase and snake_case
            $now = date('Y-m-d H:i:s');

            // Remove any timestamp fields from service data - we'll add them with correct names
            unset($service['createdAt']);
            unset($service['updatedAt']);
            unset($service['created_at']);
            unset($service['updated_at']);

            if ($existing) {
                // Update existing service
                unset($service['id']); // Don't update the ID
                $service['updated_at'] = $now; // Database uses snake_case

                $db->table('services')->where('id', $existing['id'])->update($service);
                echo "Updated service: {$service['title']}\n";
            } else {
                // Insert new service - database uses snake_case for timestamps
                $service['created_at'] = $now;
                $service['updated_at'] = $now;

                $db->table('services')->insert($service);
                echo "Created service: {$service['title']}\n";
            }
        }

        echo "✅ Services seeded successfully!\n";
    }
}
