<?php namespace App\Models;
use CodeIgniter\Model;

class CandidatePersonalInfo extends Model
{
    protected $table = 'candidate_personal_info';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $protectFields = true;

    protected $allowedFields = [
        'id','candidateId','fullName','dob','gender','idNumber','nationality','countyOfOrigin','plwd','createdAt','updatedAt'
    ];
}