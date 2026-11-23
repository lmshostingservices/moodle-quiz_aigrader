@mod @mod_quiz @quiz @quiz_aigrader
Feature: Assessors see the essays they may mark in the AI Essay Grader
  In order to mark only the learners I am responsible for
  As an assessor
  I need the marking queue to list finished essays from my own groups

  Background:
    Given the following "users" exist:
      | username  | firstname | lastname |
      | teacher1  | Sarah     | Mitchell |
      | assessor1 | Daniel    | Brooks   |
      | student1  | Priya     | Sharma   |
      | student2  | Grace     | Nguyen   |
    And the following "courses" exist:
      | fullname                   | shortname | groupmode | groupmodeforce |
      | Work health and safety     | WHS       | 1         | 1              |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | teacher1  | WHS    | editingteacher |
      | assessor1 | WHS    | teacher        |
      | student1  | WHS    | student        |
      | student2  | WHS    | student        |
    And the following "groups" exist:
      | name             | course | idnumber |
      | Monday cohort    | WHS    | G1       |
      | Thursday cohort  | WHS    | G2       |
    And the following "group members" exist:
      | user      | group |
      | assessor1 | G1    |
      | student1  | G1    |
      | student2  | G2    |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | WHS       | Task questions |
    And the following "questions" exist:
      | questioncategory | qtype | name         | questiontext                                    |
      | Task questions   | essay | Consultation | Explain how a business must consult its workers |
    And the following "activities" exist:
      | activity | name                     | course | idnumber | groupmode |
      | quiz     | Task 2 Knowledge quiz    | WHS    | quiz1    | 1         |
    And quiz "Task 2 Knowledge quiz" contains the following questions:
      | question     | page |
      | Consultation | 1    |
    And user "student1" has attempted "Task 2 Knowledge quiz" with responses:
      | slot | response                                                  |
      | 1    | Consultation means sharing information with the workers. |
    And user "student2" has attempted "Task 2 Knowledge quiz" with responses:
      | slot | response                                   |
      | 1    | Workers are told about safety at meetings. |

  Scenario: An editing teacher sees every learner's essay in the marking queue
    When I am on the "Task 2 Knowledge quiz" "quiz_aigrader > Report" page logged in as "teacher1"
    Then I should see "AI Essay Grader"
    And I should see "Priya Sharma" in the ".aigrader-queue" "css_element"
    And I should see "Grace Nguyen" in the ".aigrader-queue" "css_element"
    And I should see "Q1" in the ".aigrader-queue" "css_element"
    And I should see "Marking criteria check"

  Scenario: An assessor under separate groups sees only their own group
    When I am on the "Task 2 Knowledge quiz" "quiz_aigrader > Report" page logged in as "assessor1"
    Then I should see "Priya Sharma" in the ".aigrader-queue" "css_element"
    And I should not see "Grace Nguyen"

  Scenario: Setup and statistics are folded under one line above the queue
    When I am on the "Task 2 Knowledge quiz" "quiz_aigrader > Report" page logged in as "teacher1"
    Then I should see "Grading setup and statistics"
    And "#aigrader-setup[open]" "css_element" should not exist
