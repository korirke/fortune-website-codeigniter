<?php namespace App\Models;
use CodeIgniter\Model;

class CandidateCourse extends Model
{
    protected $table = 'candidate_courses';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $protectFields = true;

    protected $allowedFields = [
        'id','candidateId','name','institution','durationWeeks','year','createdAt','updatedAt'
    ];
}