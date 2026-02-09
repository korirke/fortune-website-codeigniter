<?php

namespace App\Models;

use CodeIgniter\Model;

class JobShortlistCriteria extends Model
{
    protected $table = 'job_shortlist_criteria';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $protectFields = true;

    protected $allowedFields = [
        'id', 'jobId',
        
        // Personal Information
        'minAge', 'maxAge', 'requiredGender', 'requiredNationality',
        'specificCounties', 'acceptPLWD', 'requirePLWD',
        
        // Education
        'requireDoctorate', 'requireMasters', 'requireBachelors',
        'specificDegreeFields', 'specificInstitutions', 'minEducationGrade',
        
        // Experience
        'minGeneralExperience', 'maxGeneralExperience',
        'minSeniorExperience', 'maxSeniorExperience',
        'specificIndustries', 'specificDomains',
        'requireMNCExperience', 'requireStartupExperience',
        'requireNGOExperience', 'requireGovernmentExperience',
        'specificJobTitles', 'requireManagementExperience', 'minTeamSizeManaged',
        'requireCurrentlyEmployed', 'excludeCurrentlyEmployed',
        
        // Skills
        'requiredSkills', 'preferredSkills', 'minSkillLevel',
        'specificLanguages', 'minLanguageProficiency',
        
        // Professional
        'requireProfessionalMembership', 'specificProfessionalBodies', 'requireGoodStanding',
        'requiredCertifications', 'preferredCertifications',
        'requireLeadershipCourse', 'minLeadershipCourseDuration',
        'minPublications', 'specificPublicationTypes',
        'requireRecentPublications', 'publicationYearsThreshold',
        
        // Clearances
        'requireTaxClearance', 'requireHELBClearance', 'requireDCIClearance',
        'requireCRBClearance', 'requireEACCClearance',
        'requireAllClearancesValid', 'maxClearanceAge',
        
        // Compensation
        'maxExpectedSalary', 'minExpectedSalary',
        'requireImmediateAvailability', 'maxNoticePeriod',
        'acceptRemoteCandidates', 'requireOnSiteCandidates', 'specificLocations',
        
        // Referees
        'requireReferees', 'minRefereeCount', 'requireSeniorReferees', 'requireAcademicReferees',
        
        // Portfolio
        'requirePortfolio', 'requireGitHubProfile', 'requireLinkedInProfile', 'minPortfolioProjects',
        
        // Custom
        'excludeInternalCandidates', 'excludePreviousApplicants', 'requireDiversityHire',
        'customCriteria',
        
        // Weights
        'educationWeight', 'experienceWeight', 'skillsWeight', 'clearanceWeight', 'professionalWeight',
        
        // Metadata
        'isActive', 'createdBy', 'createdAt', 'updatedAt'
    ];

    protected $useTimestamps = false;
    protected $dateFormat = 'datetime';
    protected $createdField = 'createdAt';
    protected $updatedField = 'updatedAt';

    protected $beforeInsert = ['setId', 'setTimestamps', 'encodeJson'];
    protected $beforeUpdate = ['setTimestamps', 'encodeJson'];
    protected $afterFind = ['decodeJson'];

    protected function setId(array $data)
    {
        if (!isset($data['data']['id'])) {
            $data['data']['id'] = uniqid('criteria_');
        }
        return $data;
    }

    protected function setTimestamps(array $data)
    {
        $now = date('Y-m-d H:i:s');
        if (!isset($data['data']['createdAt'])) {
            $data['data']['createdAt'] = $now;
        }
        $data['data']['updatedAt'] = $now;
        return $data;
    }

    protected function encodeJson(array $data)
    {
        $jsonFields = [
            'specificCounties', 'specificDegreeFields', 'specificInstitutions',
            'specificIndustries', 'specificDomains', 'specificJobTitles',
            'requiredSkills', 'preferredSkills', 'specificLanguages',
            'specificProfessionalBodies', 'requiredCertifications', 'preferredCertifications',
            'specificPublicationTypes', 'specificLocations', 'customCriteria'
        ];

        foreach ($jsonFields as $field) {
            if (isset($data['data'][$field]) && is_array($data['data'][$field])) {
                $data['data'][$field] = json_encode($data['data'][$field]);
            }
        }

        return $data;
    }

    protected function decodeJson(array $data)
    {
        $jsonFields = [
            'specificCounties', 'specificDegreeFields', 'specificInstitutions',
            'specificIndustries', 'specificDomains', 'specificJobTitles',
            'requiredSkills', 'preferredSkills', 'specificLanguages',
            'specificProfessionalBodies', 'requiredCertifications', 'preferredCertifications',
            'specificPublicationTypes', 'specificLocations', 'customCriteria'
        ];

        if (isset($data['data'])) {
            $record = &$data['data'];
        } elseif (isset($data[0])) {
            foreach ($data as &$record) {
                $this->decodeJsonRecord($record, $jsonFields);
            }
            return $data;
        } else {
            $record = &$data;
        }

        $this->decodeJsonRecord($record, $jsonFields);
        return $data;
    }

    private function decodeJsonRecord(&$record, $jsonFields)
    {
        foreach ($jsonFields as $field) {
            if (isset($record[$field]) && is_string($record[$field])) {
                $decoded = json_decode($record[$field], true);
                $record[$field] = $decoded ?? [];
            }
        }
    }
}