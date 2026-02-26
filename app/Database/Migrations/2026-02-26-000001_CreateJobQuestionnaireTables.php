<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateJobQuestionnaireTables extends Migration
{
    public function up()
    {
        // job_questionnaires
        $this->forge->addField([
            'id' => ['type' => 'VARCHAR', 'constraint' => 191],
            'jobId' => ['type' => 'VARCHAR', 'constraint' => 191],
            'title' => ['type' => 'VARCHAR', 'constraint' => 191, 'default' => 'Screening Questions'],
            'description' => ['type' => 'TEXT', 'null' => true],
            'isActive' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'createdAt' => ['type' => 'DATETIME', 'null' => false],
            'updatedAt' => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('jobId', 'job_questionnaires_jobId_unique');
        $this->forge->addForeignKey('jobId', 'jobs', 'id', 'CASCADE', 'CASCADE', 'job_questionnaires_jobId_fkey');
        $this->forge->createTable('job_questionnaires', true);

        // job_questions
        $this->forge->addField([
            'id' => ['type' => 'VARCHAR', 'constraint' => 191],
            'jobId' => ['type' => 'VARCHAR', 'constraint' => 191],
            'questionnaireId' => ['type' => 'VARCHAR', 'constraint' => 191],
            'questionText' => ['type' => 'TEXT', 'null' => false],
            'type' => ['type' => 'ENUM', 'constraint' => ['YES_NO', 'OPEN_ENDED'], 'default' => 'OPEN_ENDED'],
            'isRequired' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'placeholder' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'sortOrder' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'createdAt' => ['type' => 'DATETIME', 'null' => false],
            'updatedAt' => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('jobId', false, false, 'job_questions_jobId_idx');
        $this->forge->addKey('questionnaireId', false, false, 'job_questions_questionnaireId_idx');
        $this->forge->addForeignKey('jobId', 'jobs', 'id', 'CASCADE', 'CASCADE', 'job_questions_jobId_fkey');
        $this->forge->addForeignKey('questionnaireId', 'job_questionnaires', 'id', 'CASCADE', 'CASCADE', 'job_questions_questionnaireId_fkey');
        $this->forge->createTable('job_questions', true);

        // application_question_answers
        $this->forge->addField([
            'id' => ['type' => 'VARCHAR', 'constraint' => 191],
            'applicationId' => ['type' => 'VARCHAR', 'constraint' => 191],
            'jobId' => ['type' => 'VARCHAR', 'constraint' => 191],
            'candidateId' => ['type' => 'VARCHAR', 'constraint' => 191],
            'questionId' => ['type' => 'VARCHAR', 'constraint' => 191],
            'answerText' => ['type' => 'TEXT', 'null' => true],
            'answerBool' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'createdAt' => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['applicationId', 'questionId'], 'application_question_answers_unique');
        $this->forge->addKey('applicationId', false, false, 'application_question_answers_applicationId_idx');
        $this->forge->addKey(['candidateId', 'jobId'], false, false, 'application_question_answers_candidate_job_idx');
        $this->forge->addKey('questionId', false, false, 'application_question_answers_questionId_idx');

        $this->forge->addForeignKey('applicationId', 'applications', 'id', 'CASCADE', 'CASCADE', 'application_question_answers_applicationId_fkey');
        $this->forge->addForeignKey('jobId', 'jobs', 'id', 'CASCADE', 'CASCADE', 'application_question_answers_jobId_fkey');
        $this->forge->addForeignKey('candidateId', 'users', 'id', 'CASCADE', 'CASCADE', 'application_question_answers_candidateId_fkey');
        $this->forge->addForeignKey('questionId', 'job_questions', 'id', 'CASCADE', 'CASCADE', 'application_question_answers_questionId_fkey');

        $this->forge->createTable('application_question_answers', true);
    }

    public function down()
    {
        $this->forge->dropTable('application_question_answers', true);
        $this->forge->dropTable('job_questions', true);
        $this->forge->dropTable('job_questionnaires', true);
    }
}
