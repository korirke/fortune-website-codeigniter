<?php namespace App\Models;
use CodeIgniter\Model;

class CandidateMembership extends Model
{
    protected $table = 'candidate_professional_memberships';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $protectFields = true;

    protected $allowedFields = [
        'id','candidateId','bodyName','membershipNumber','isActive','goodStanding','createdAt','updatedAt'
    ];
}