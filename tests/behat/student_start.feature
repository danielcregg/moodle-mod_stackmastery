@mod @mod_stackmastery
Feature: Students see the start affordance and teachers see the placeholder
  In order to begin a mastery check
  As a student
  I need the landing page to offer exactly the actions my role allows

  # Behat never presses Start here: the attempt pages land in a later package. This feature pins
  # visibility, capabilities and button states only, on a shortanswer pool (no Maxima in CI).
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

  Scenario: A student sees the intro facts and the Start button
    Given I am on the "Mastery check" "stackmastery activity" page logged in as "student1"
    Then I should see "Skills"
    And I should see "Differentiation"
    And I should see "Target mastery"
    And "Start attempt" "button" should exist
    And I should not see "Students see a Start button here."

  Scenario: A teacher sees the placeholder and the report link, never the Start button
    Given I am on the "Mastery check" "stackmastery activity" page logged in as "teacher1"
    Then I should see "Students see a Start button here."
    And "Start attempt" "button" should not exist
    And I should see "Report"

  Scenario: A user who is not enrolled cannot reach the activity
    Given the following "users" exist:
      | username | firstname | lastname |
      | outsider | Olly      | Out      |
    And I log in as "outsider"
    When I am on the "Mastery check" "stackmastery activity" page
    Then I should see "You cannot enrol yourself in this course"
