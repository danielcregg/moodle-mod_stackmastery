@mod @mod_stackmastery
Feature: Teachers create a mastery check with a valid question pool
  In order to run an adaptive mastery check
  As a teacher
  I need pool coverage to be validated when I save the activity

  # Behat in CI has no Maxima, so no scenario may instantiate a STACK question: the pool uses
  # core shortanswer questions through the allowedqtypes admin seam. STACK-specific interaction
  # is covered by the CAS-gated PHPUnit group and the live VM E2E.
  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Terry     | Teacher  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following config values are set as admin:
      | allowedqtypes | shortanswer | mod_stackmastery |
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

  Scenario: An empty (skill, difficulty) cell hard-blocks saving
    Given I log in as "teacher1"
    When I add a "stackmastery" activity to course "Course 1" section "1"
    And I set the following fields to these values:
      | Name                   | Mastery check |
      | Question pool category | C1: Pool      |
    And I press "Save and return to course"
    Then I should see "The pool has no questions for"
    And I should see "New STACK Mastery"

  Scenario: A fully covered single-skill pool saves, with a thin-cell warning
    Given I log in as "teacher1"
    When I add a "stackmastery" activity to course "Course 1" section "1"
    And I set the following fields to these values:
      | Name                   | Mastery check |
      | Question pool category | C1: Pool      |
      | Integration            | 0             |
      | Expanding brackets     | 0             |
      | Factorising            | 0             |
      | Simplifying fractions  | 0             |
      | Linear equations       | 0             |
      | Quadratic equations    | 0             |
      | Numerical evaluation   | 0             |
    And I press "Save and display"
    # Probes: each names the exact failure state if it trips (form re-render vs missing banner).
    # differentiate/* in the error = tags invisible to the eligibility SQL under Behat;
    # integrate/* alone = the seven advcheckbox unchecks never reached validation.
    Then I should not see "differentiate/easy"
    And I should not see "integrate/easy"
    And I should not see "The pool has no questions for"
    And I should not see "Select at least one skill"
    And I should not see "Choose a valid target mastery level"
    And I should not see "The question budget must be between 1 and 500"
    And I should not see "New STACK Mastery"
    And I should see "Mastery check"
    And I should see "Thin question pool"
