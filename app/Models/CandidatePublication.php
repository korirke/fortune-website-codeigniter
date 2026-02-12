<?php namespace App\Models;
use CodeIgniter\Model;

class CandidatePublication extends Model
{
    protected $table = 'candidate_publications';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $protectFields = true;

    protected $allowedFields = [
        'id','candidateId','title','type','journalOrPublisher','year','link','createdAt','updatedAt'
    ];
}