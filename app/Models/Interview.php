<?php

namespace App\Models;

use CodeIgniter\Model;

class Interview extends Model
{
    protected $table = 'interviews';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'id',
        'applicationId',
        'jobId',
        'candidateId',
        'scheduledAt',
        'duration',
        'status',
        'type',
        'location',
        'meetingLink',
        'meetingId',
        'meetingPassword',
        'interviewerName',
        'interviewerId',
        'notes',
        'feedback',
        'rating',
        'outcome',
        'attachments',
        'reminderSent',
        'reminderSentAt',
        'createdBy',
        'createdAt',
        'updatedAt'
    ];

    protected $useTimestamps = true;
    protected $createdField = 'createdAt';
    protected $updatedField = 'updatedAt';

    protected $validationRules = [
        'applicationId' => 'required|string',
        'jobId' => 'required|string',
        'candidateId' => 'required|string',
        'scheduledAt' => 'required|valid_date',
        'type' => 'required|in_list[PHONE,VIDEO,IN_PERSON,TECHNICAL,HR_SCREENING,PANEL]',
        'status' => 'permit_empty|in_list[SCHEDULED,IN_PROGRESS,COMPLETED,CANCELLED,RESCHEDULED,NO_SHOW]',
        'duration' => 'permit_empty|integer|greater_than[0]',
        'rating' => 'permit_empty|integer|greater_than[0]|less_than_equal_to[5]'
    ];

    protected $validationMessages = [
        'scheduledAt' => [
            'required' => 'Interview date and time is required',
            'valid_date' => 'Please provide a valid date and time'
        ],
        'type' => [
            'required' => 'Interview type is required',
            'in_list' => 'Invalid interview type'
        ]
    ];

    protected $beforeInsert = ['generateId'];
    protected $beforeUpdate = [];

    /**
     * Generate unique ID before insert
     */
    protected function generateId(array $data)
    {
        if (!isset($data['data']['id'])) {
            $data['data']['id'] = uniqid('interview_');
        }
        return $data;
    }

    /**
     * Get interview with full details (candidate, job, interviewer, history)
     */
    public function getInterviewWithDetails($interviewId, $userId = null, $userRole = null)
    {
        $builder = $this->db->table($this->table . ' i');
        
        $builder->select('i.*')
            ->select('c.id as candidate_id, c.firstName as candidate_firstName, c.lastName as candidate_lastName, 
                      c.email as candidate_email, c.phone as candidate_phone')
            ->select('cp.title as candidate_title, cp.location as candidate_location, cp.resumeUrl as candidate_resumeUrl')
            ->select('j.id as job_id, j.title as job_title')
            ->select('comp.id as company_id, comp.name as company_name, comp.logo as company_logo')
            ->select('app.id as application_id, app.status as application_status, app.coverLetter, app.appliedAt')
            ->select('interviewer.id as interviewer_id, interviewer.firstName as interviewer_firstName, 
                      interviewer.lastName as interviewer_lastName, interviewer.email as interviewer_email')
            ->join('users c', 'c.id = i.candidateId', 'left')
            ->join('candidate_profiles cp', 'cp.userId = c.id', 'left')
            ->join('jobs j', 'j.id = i.jobId', 'left')
            ->join('companies comp', 'comp.id = j.companyId', 'left')
            ->join('applications app', 'app.id = i.applicationId', 'left')
            ->join('users interviewer', 'interviewer.id = i.interviewerId', 'left')
            ->where('i.id', $interviewId);

        // Authorization filter
        if ($userRole === 'CANDIDATE' && $userId) {
            $builder->where('i.candidateId', $userId);
        } elseif ($userRole === 'EMPLOYER' && $userId) {
            // Get employer's company
            $employerProfile = $this->db->table('employer_profiles')
                ->where('userId', $userId)
                ->get()
                ->getRowArray();
            
            if ($employerProfile) {
                $builder->where('comp.id', $employerProfile['companyId']);
            }
        }

        $interview = $builder->get()->getRowArray();

        if (!$interview) {
            return null;
        }

        // Format nested data
        $formattedInterview = [
            'id' => $interview['id'],
            'applicationId' => $interview['applicationId'],
            'jobId' => $interview['jobId'],
            'candidateId' => $interview['candidateId'],
            'scheduledAt' => $interview['scheduledAt'],
            'duration' => (int) $interview['duration'],
            'status' => $interview['status'],
            'type' => $interview['type'],
            'location' => $interview['location'],
            'meetingLink' => $interview['meetingLink'],
            'meetingId' => $interview['meetingId'],
            'meetingPassword' => $interview['meetingPassword'],
            'interviewerName' => $interview['interviewerName'],
            'interviewerId' => $interview['interviewerId'],
            'notes' => $interview['notes'],
            'feedback' => $interview['feedback'],
            'rating' => $interview['rating'] ? (int) $interview['rating'] : null,
            'outcome' => $interview['outcome'],
            'reminderSent' => (bool) $interview['reminderSent'],
            'reminderSentAt' => $interview['reminderSentAt'],
            'createdAt' => $interview['createdAt'],
            'updatedAt' => $interview['updatedAt'],
            'candidate' => [
                'id' => $interview['candidate_id'],
                'firstName' => $interview['candidate_firstName'],
                'lastName' => $interview['candidate_lastName'],
                'email' => $interview['candidate_email'],
                'phone' => $interview['candidate_phone'],
                'candidateProfile' => [
                    'title' => $interview['candidate_title'],
                    'location' => $interview['candidate_location'],
                    'resumeUrl' => $interview['candidate_resumeUrl']
                ]
            ],
            'job' => [
                'id' => $interview['job_id'],
                'title' => $interview['job_title'],
                'company' => [
                    'id' => $interview['company_id'],
                    'name' => $interview['company_name'],
                    'logo' => $interview['company_logo']
                ]
            ],
            'application' => [
                'id' => $interview['application_id'],
                'status' => $interview['application_status'],
                'coverLetter' => $interview['coverLetter'],
                'appliedAt' => $interview['appliedAt']
            ]
        ];

        if ($interview['interviewer_id']) {
            $formattedInterview['interviewer'] = [
                'id' => $interview['interviewer_id'],
                'firstName' => $interview['interviewer_firstName'],
                'lastName' => $interview['interviewer_lastName'],
                'email' => $interview['interviewer_email']
            ];
        }

        // Get interview history
        $history = $this->db->table('interview_history ih')
            ->select('ih.*, u.firstName, u.lastName')
            ->join('users u', 'u.id = ih.changedBy', 'left')
            ->where('ih.interviewId', $interviewId)
            ->orderBy('ih.changedAt', 'DESC')
            ->get()
            ->getResultArray();

        $formattedInterview['history'] = array_map(function($h) {
            return [
                'id' => $h['id'],
                'interviewId' => $h['interviewId'],
                'fromStatus' => $h['fromStatus'],
                'toStatus' => $h['toStatus'],
                'changedBy' => $h['changedBy'],
                'reason' => $h['reason'],
                'notes' => $h['notes'],
                'changedAt' => $h['changedAt'],
                'user' => [
                    'firstName' => $h['firstName'],
                    'lastName' => $h['lastName']
                ]
            ];
        }, $history);

        return $formattedInterview;
    }

    /**
     * Search interviews with filters
     */
    public function searchInterviews($filters, $userId = null, $userRole = null)
    {
        $builder = $this->db->table($this->table . ' i');
        
        $builder->select('i.*')
            ->select('c.firstName as candidate_firstName, c.lastName as candidate_lastName, 
                      c.email as candidate_email, c.phone as candidate_phone')
            ->select('j.title as job_title')
            ->select('comp.name as company_name')
            ->select('interviewer.firstName as interviewer_firstName, interviewer.lastName as interviewer_lastName')
            ->join('users c', 'c.id = i.candidateId', 'left')
            ->join('jobs j', 'j.id = i.jobId', 'left')
            ->join('companies comp', 'comp.id = j.companyId', 'left')
            ->join('users interviewer', 'interviewer.id = i.interviewerId', 'left');

        // Authorization filter
        if ($userRole === 'CANDIDATE' && $userId) {
            $builder->where('i.candidateId', $userId);
        } elseif ($userRole === 'EMPLOYER' && $userId) {
            $employerProfile = $this->db->table('employer_profiles')
                ->where('userId', $userId)
                ->get()
                ->getRowArray();
            
            if ($employerProfile) {
                $builder->where('comp.id', $employerProfile['companyId']);
            }
        }

        // Apply filters
        if (!empty($filters['query'])) {
            $builder->groupStart()
                ->like('c.firstName', $filters['query'])
                ->orLike('c.lastName', $filters['query'])
                ->orLike('c.email', $filters['query'])
                ->orLike('j.title', $filters['query'])
                ->groupEnd();
        }

        if (!empty($filters['status'])) {
            $builder->where('i.status', $filters['status']);
        }

        if (!empty($filters['type'])) {
            $builder->where('i.type', $filters['type']);
        }

        if (!empty($filters['jobId'])) {
            $builder->where('i.jobId', $filters['jobId']);
        }

        if (!empty($filters['candidateId'])) {
            $builder->where('i.candidateId', $filters['candidateId']);
        }

        if (!empty($filters['interviewerId'])) {
            $builder->where('i.interviewerId', $filters['interviewerId']);
        }

        if (!empty($filters['startDate'])) {
            $builder->where('i.scheduledAt >=', $filters['startDate']);
        }

        if (!empty($filters['endDate'])) {
            $builder->where('i.scheduledAt <=', $filters['endDate']);
        }

        // Count total
        $total = $builder->countAllResults(false);

        // Pagination
        $page = $filters['page'] ?? 1;
        $limit = $filters['limit'] ?? 20;
        $offset = ($page - 1) * $limit;

        $builder->orderBy('i.scheduledAt', 'DESC')
            ->limit($limit, $offset);

        $interviews = $builder->get()->getResultArray();

        // Format results
        $formattedInterviews = array_map(function($interview) {
            return [
                'id' => $interview['id'],
                'applicationId' => $interview['applicationId'],
                'jobId' => $interview['jobId'],
                'candidateId' => $interview['candidateId'],
                'scheduledAt' => $interview['scheduledAt'],
                'duration' => (int) $interview['duration'],
                'status' => $interview['status'],
                'type' => $interview['type'],
                'location' => $interview['location'],
                'meetingLink' => $interview['meetingLink'],
                'interviewerName' => $interview['interviewerName'],
                'notes' => $interview['notes'],
                'rating' => $interview['rating'] ? (int) $interview['rating'] : null,
                'reminderSent' => (bool) $interview['reminderSent'],
                'createdAt' => $interview['createdAt'],
                'candidate' => [
                    'firstName' => $interview['candidate_firstName'],
                    'lastName' => $interview['candidate_lastName'],
                    'email' => $interview['candidate_email'],
                    'phone' => $interview['candidate_phone']
                ],
                'job' => [
                    'title' => $interview['job_title'],
                    'company' => [
                        'name' => $interview['company_name']
                    ]
                ],
                'interviewer' => $interview['interviewer_firstName'] ? [
                    'firstName' => $interview['interviewer_firstName'],
                    'lastName' => $interview['interviewer_lastName']
                ] : null
            ];
        }, $interviews);

        return [
            'interviews' => $formattedInterviews,
            'pagination' => [
                'total' => $total,
                'page' => (int) $page,
                'limit' => (int) $limit,
                'totalPages' => ceil($total / $limit)
            ]
        ];
    }

    /**
     * Get upcoming interviews (next 7 days)
     */
    public function getUpcomingInterviews($userId = null, $userRole = null)
    {
        $now = date('Y-m-d H:i:s');
        $next7Days = date('Y-m-d H:i:s', strtotime('+7 days'));

        $builder = $this->db->table($this->table . ' i');
        
        $builder->select('i.*')
            ->select('c.firstName as candidate_firstName, c.lastName as candidate_lastName, c.email as candidate_email')
            ->select('j.title as job_title')
            ->join('users c', 'c.id = i.candidateId', 'left')
            ->join('jobs j', 'j.id = i.jobId', 'left')
            ->where('i.scheduledAt >=', $now)
            ->where('i.scheduledAt <=', $next7Days)
            ->where('i.status', 'SCHEDULED');

        // Authorization filter
        if ($userRole === 'CANDIDATE' && $userId) {
            $builder->where('i.candidateId', $userId);
        } elseif ($userRole === 'EMPLOYER' && $userId) {
            $employerProfile = $this->db->table('employer_profiles')
                ->where('userId', $userId)
                ->get()
                ->getRowArray();
            
            if ($employerProfile) {
                $builder->join('companies comp', 'comp.id = j.companyId', 'left')
                    ->where('comp.id', $employerProfile['companyId']);
            }
        }

        $builder->orderBy('i.scheduledAt', 'ASC');

        return $builder->get()->getResultArray();
    }

    /**
     * Get interview statistics
     */
    public function getStatistics($userId = null, $userRole = null)
    {
        $builder = $this->db->table($this->table . ' i');

        // Authorization filter
        if ($userRole === 'EMPLOYER' && $userId) {
            $employerProfile = $this->db->table('employer_profiles')
                ->where('userId', $userId)
                ->get()
                ->getRowArray();
            
            if ($employerProfile) {
                $builder->join('jobs j', 'j.id = i.jobId', 'left')
                    ->where('j.companyId', $employerProfile['companyId']);
            }
        }

        $total = $builder->countAllResults(false);

        $scheduled = clone $builder;
        $scheduledCount = $scheduled->where('i.status', 'SCHEDULED')->countAllResults();

        $completed = clone $builder;
        $completedCount = $completed->where('i.status', 'COMPLETED')->countAllResults();

        $cancelled = clone $builder;
        $cancelledCount = $cancelled->where('i.status', 'CANCELLED')->countAllResults();

        $upcoming = clone $builder;
        $upcomingCount = $upcoming->where('i.status', 'SCHEDULED')
            ->where('i.scheduledAt >=', date('Y-m-d H:i:s'))
            ->countAllResults();

        return [
            'total' => $total,
            'scheduled' => $scheduledCount,
            'completed' => $completedCount,
            'cancelled' => $cancelledCount,
            'upcoming' => $upcomingCount
        ];
    }
}