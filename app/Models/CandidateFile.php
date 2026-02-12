<?php namespace App\Models;
use CodeIgniter\Model;

class CandidateFile extends Model
{
    protected $table = 'candidate_files';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $protectFields = true;

    protected $allowedFields = [
        'id','candidateId','category','title','fileName','fileUrl','mimeType','fileSize','createdAt','updatedAt'
    ];
}