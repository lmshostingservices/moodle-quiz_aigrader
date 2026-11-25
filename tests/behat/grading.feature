@mod @mod_quiz @quiz @quiz_aigrader @javascript
Feature: Graders review and approve AI suggestions in the Essay Grader
  In order to mark essays faster without giving up control of the result
  As a grader
  I need to review each AI suggestion, approve it myself, and have learners told

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

  Scenario: A grader asks for a suggestion, approves it, and the learner is told AI helped
    Given the Essay Grader service is simulated
    And I am on the "Task 2 Knowledge quiz" "quiz_aigrader > Report" page logged in as "teacher1"
    When I ask the Essay Grader to suggest a mark for "Priya Sharma"
    And I wait until "Approve & Save to Gradebook" "button" exists
    Then I should see "You explained what consultation means for workers."
    And I click on "Approve & Save to Gradebook" "button"
    And I wait until ".aigrader-btn-saved" "css_element" exists
    And I am on the "Task 2 Knowledge quiz > student1 > Attempt 1" "mod_quiz > Attempt review" page
    And I should see "This feedback was drafted with the help of AI, then checked and approved by Sarah Mitchell"

  Scenario: A refused site key is reported and no mark is offered
    Given the Essay Grader service refuses the site key
    And I am on the "Task 2 Knowledge quiz" "quiz_aigrader > Report" page logged in as "teacher1"
    When I ask the Essay Grader to suggest a mark for "Priya Sharma"
    Then I should see "The grading service did not accept this site's API key"
    And "Approve & Save to Gradebook" "button" should not exist

  Scenario: An administrator pauses AI suggestions for the whole site
    Given the Essay Grader service is simulated
    And AI suggestions are paused on the site
    When I am on the "Task 2 Knowledge quiz" "quiz_aigrader > Report" page logged in as "teacher1"
    Then I should see "AI suggestions are paused"
    And "#aigrader-gradeall-btn" "css_element" should not be visible

  Scenario: Graders see guidance on using AI suggestions well
    Given the Essay Grader service is simulated
    And I am on the "Task 2 Knowledge quiz" "quiz_aigrader > Report" page logged in as "teacher1"
    When I click on "Grading setup and statistics" "text"
    Then I should see "Using AI suggestions well"
    And I should see "The suggested mark is a draft. You make the decision, and you are responsible for it."
