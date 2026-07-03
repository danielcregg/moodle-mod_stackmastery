@mod @mod_stackmastery
Feature: Teachers can fill the question pool from the activity page
  In order to stock a mastery check with questions quickly
  As a teacher
  I need one-click pool tools on the activity page

  # Non-JS render checks only: nothing is imported or queued here. local_stackforge is NOT
  # installed in CI, so the "Build my pool" form must be absent - asserting its absence documents
  # the gating (that form renders only when \local_stackforge\generator::queue_generation exists;
  # the sample pool ships inside this module and is always offered to pool managers).
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
    And the following "question categories" exist:
      | contextlevel | reference | name |
      | Course       | C1        | Pool |
    And the following "questions" exist:
      | questioncategory | qtype       | name | template |
      | Pool             | shortanswer | Q1   | frogtoad |
    And the following "core_question > Tags" exist:
      | question | tag                              |
      | Q1       | stackmastery_skill_differentiate |
      | Q1       | stackmastery_diff_easy           |
    And the following "activities" exist:
      | activity     | course | name          | idnumber | poolcategory | skills        |
      | stackmastery | C1     | Mastery check | SM1      | Pool         | differentiate |

  Scenario: A teacher gets the sample pool always and the AI builder only with the forge installed
    Given I am on the "Mastery check" "stackmastery activity" page logged in as "teacher1"
    Then I should see "Question pool tools"
    And "Load sample pool" "button" should exist
    And "Build my pool" "button" should not exist

  Scenario: A student never sees the pool tools
    Given I am on the "Mastery check" "stackmastery activity" page logged in as "student1"
    Then I should not see "Question pool tools"
    And "Load sample pool" "button" should not exist
