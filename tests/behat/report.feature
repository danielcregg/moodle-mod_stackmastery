@mod @mod_stackmastery
Feature: Teachers monitor mastery attempts in the report
  In order to follow my students' mastery progress
  As a teacher
  I need a report with the cohort funnel, per-attempt rows, downloads and a delete escape hatch

  # Behat in CI has no Maxima, so the pool uses core shortanswer questions through the
  # allowedqtypes admin seam. The seeded attempt is walked through the real UI (two correct
  # answers cross the 0.85 target deterministically; see attempt_flow.feature).
  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Terry     | Teacher  |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following config values are set as admin:
      | allowedqtypes | shortanswer | mod_stackmastery |
      | epsilon       | 0           | mod_stackmastery |
    And the following "question categories" exist:
      | contextlevel | reference | name |
      | Course       | C1        | Pool |
    And the following "questions" exist:
      | questioncategory | qtype       | name | template |
      | Pool             | shortanswer | Q1   | frogtoad |
      | Pool             | shortanswer | Q2   | frogtoad |
      | Pool             | shortanswer | Q3   | frogtoad |
    And the following "core_question > Tags" exist:
      | question | tag                              |
      | Q1       | stackmastery_skill_differentiate |
      | Q1       | stackmastery_diff_easy           |
      | Q2       | stackmastery_skill_differentiate |
      | Q2       | stackmastery_diff_medium         |
      | Q3       | stackmastery_skill_differentiate |
      | Q3       | stackmastery_diff_hard           |
    And the following "activities" exist:
      | activity     | course | name          | idnumber | poolcategory | skills        | targetmastery | budget |
      | stackmastery | C1     | Mastery check | SM1      | Pool         | differentiate | 0.85          | 5      |

  Scenario: The report is empty before any attempts
    Given I am on the "Mastery check" "stackmastery activity" page logged in as "teacher1"
    When I navigate to "Report" in current page administration
    Then I should see "Started"
    And I should see "Reached the target"
    And I should see "Nothing to display"
    And I should see "Group mode is not supported in this version"

  Scenario: A walked attempt shows in the funnel and the table, and can be deleted
    Given I am on the "Mastery check" "stackmastery activity" page logged in as "student1"
    And I press "Start attempt"
    And I set the field "Answer" to "frog"
    And I press "Check"
    And I set the field "Answer" to "frog"
    And I press "Check"
    And I log out
    When I am on the "Mastery check" "stackmastery activity" page logged in as "teacher1"
    And I navigate to "Report" in current page administration
    Then I should see "Started"
    And I should see "Sam Student"
    And I should see "Yes" in the "Sam Student" "table_row"
    And I should see "Complete (Reached target)" in the "Sam Student" "table_row"
    And I should see "Download table data as"
    When I click on "Delete attempt" "link" in the "Sam Student" "table_row"
    Then I should see "Delete this attempt?"
    When I press "Delete attempt"
    Then I should see "Attempt deleted and grade recalculated."
    And I should not see "Sam Student"
    And I should see "Nothing to display"

  Scenario: Students get no report entry point
    Given I am on the "Mastery check" "stackmastery activity" page logged in as "student1"
    Then "Report" "link" should not exist in current page administration
