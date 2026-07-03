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

  Scenario: A fully covered pool saves, with a thin-cell warning
    # All 8 skills stay selected (the form default). Unchecking advcheckboxes is unreliable
    # under the non-JS driver (the paired hidden input swallows the set - verified by cell
    # probes in CI), so full coverage comes from tagging one multi-difficulty question per
    # remaining skill. Skill SUBSETTING is covered by PHPUnit validate_selection tests and
    # the live JS E2E.
    Given the following "questions" exist:
      | questioncategory | qtype       | name | template |
      | Pool             | shortanswer | Q4   | frogtoad |
      | Pool             | shortanswer | Q5   | frogtoad |
      | Pool             | shortanswer | Q6   | frogtoad |
      | Pool             | shortanswer | Q7   | frogtoad |
      | Pool             | shortanswer | Q8   | frogtoad |
      | Pool             | shortanswer | Q9   | frogtoad |
      | Pool             | shortanswer | Q10  | frogtoad |
    And the following "core_question > Tags" exist:
      | question | tag                               |
      | Q1       | stackmastery_diff_medium          |
      | Q1       | stackmastery_diff_hard            |
      | Q4       | stackmastery_skill_integrate      |
      | Q4       | stackmastery_diff_easy            |
      | Q4       | stackmastery_diff_medium          |
      | Q4       | stackmastery_diff_hard            |
      | Q5       | stackmastery_skill_expand         |
      | Q5       | stackmastery_diff_easy            |
      | Q5       | stackmastery_diff_medium          |
      | Q5       | stackmastery_diff_hard            |
      | Q6       | stackmastery_skill_factor         |
      | Q6       | stackmastery_diff_easy            |
      | Q6       | stackmastery_diff_medium          |
      | Q6       | stackmastery_diff_hard            |
      | Q7       | stackmastery_skill_simplify       |
      | Q7       | stackmastery_diff_easy            |
      | Q7       | stackmastery_diff_medium          |
      | Q7       | stackmastery_diff_hard            |
      | Q8       | stackmastery_skill_solve_linear   |
      | Q8       | stackmastery_diff_easy            |
      | Q8       | stackmastery_diff_medium          |
      | Q8       | stackmastery_diff_hard            |
      | Q9       | stackmastery_skill_solve_quadratic |
      | Q9       | stackmastery_diff_easy            |
      | Q9       | stackmastery_diff_medium          |
      | Q9       | stackmastery_diff_hard            |
      | Q10      | stackmastery_skill_numerical      |
      | Q10      | stackmastery_diff_easy            |
      | Q10      | stackmastery_diff_medium          |
      | Q10      | stackmastery_diff_hard            |
    And I log in as "teacher1"
    When I add a "stackmastery" activity to course "Course 1" section "1"
    And I set the following fields to these values:
      | Name                   | Mastery check |
      | Question pool category | C1: Pool      |
    And I press "Save and display"
    Then I should not see "The pool has no questions for"
    And I should not see "New STACK Mastery"
    And I should see "Mastery check"
    And I should see "Thin question pool"
