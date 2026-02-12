<?php namespace App\Models;
use CodeIgniter\Model;

class CandidateClearance extends Model
{
    protected $table = 'candidate_clearances';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $protectFields = true;

    protected $allowedFields = [
        'id','candidateId','type','certificateNumber','issueDate','expiryDate','status','createdAt','updatedAt'
    ];
}