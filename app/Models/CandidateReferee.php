<?php namespace App\Models;
use CodeIgniter\Model;

class CandidateReferee extends Model
{
    protected $table = 'candidate_referees';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $protectFields = true;

    protected $allowedFields = [
        'id','candidateId','name','position','organization','phone','email','createdAt','updatedAt'
    ];
}