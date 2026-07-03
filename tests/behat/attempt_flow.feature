@mod @mod_stackmastery @javascript
Feature: A student works through an adaptive mastery attempt
  In order to reach the mastery target
  As a student
  I need to answer adaptively served questions and see my progress move

  # Behat in CI has no Maxima, so the pool uses core shortanswer questions through the
  # allowedqtypes admin seam (the attempt engine is qtype-agnostic). Determinism under ANY
  # policy path and epsilon: every answer below is correct ("frog" grades 1.0 under
  # adaptivenopenalty) and with target mastery 0.85 the differentiate skill crosses the target
  # on exactly the SECOND correct answer for every difficulty sequence the policy can serve
  # (BKT worst case equals best case at these parameters), so the budget of 5 never bites and
  # the flow is fixed. Epsilon 0 is snapshotted at instance creation as an extra belt.
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
      | Pool             | shortanswer | Q4   | frogtoad |
      | Pool             | shortanswer | Q5   | frogtoad |
      | Pool             | shortanswer | Q6   | frogtoad |
    And the following "core_question > Tags" exist:
      | question | tag                              |
      | Q1       | stackmastery_skill_differentiate |
      | Q1       | stackmastery_diff_easy           |
      | Q2       | stackmastery_skill_differentiate |
      | Q2       | stackmastery_diff_easy           |
      | Q3       | stackmastery_skill_differentiate |
      | Q3       | stackmastery_diff_medium         |
      | Q4       | stackmastery_skill_differentiate |
      | Q4       | stackmastery_diff_medium         |
      | Q5       | stackmastery_skill_differentiate |
      | Q5       | stackmastery_diff_hard           |
      | Q6       | stackmastery_skill_differentiate |
      | Q6       | stackmastery_diff_hard           |
    And the following "activities" exist:
      | activity     | course | name          | idnumber | poolcategory | skills        | targetmastery | budget |
      | stackmastery | C1     | Mastery check | SM1      | Pool         | differentiate | 0.85          | 5      |

  Scenario: Correct answers move the mastery bars, show the review panel and finish at the target
    Given I am on the "Mastery check" "stackmastery activity" page logged in as "student1"
    When I press "Start attempt"
    Then I should see "Question 1 of up to 5"
    And I should see "Your mastery"
    And I should see "Differentiation"
    And I should see "20%"
    And I should see "Skills at target: 0 of 1"
    # Resume never draws: a reload re-renders the same open question.
    When I reload the page
    Then I should see "Question 1 of up to 5"
    # Field and button are scoped to the open-question form: on review pages the read-only
    # review panel above it repeats the same "Answer" label (its input ignores keystrokes).
    When I set the field "Answer" in the "#stackmastery-attemptform" "css_element" to "frog"
    And I click on "Check" "button" in the "#stackmastery-attemptform" "css_element"
    Then I should see "Correct."
    And I should see "Your mastery in Differentiation moved from 20%"
    And I should see "Question 2 of up to 5"
    When I set the field "Answer" in the "#stackmastery-attemptform" "css_element" to "frog"
    And I click on "Check" "button" in the "#stackmastery-attemptform" "css_element"
    Then I should see "You reached the target mastery in every skill."
    And I should see "Complete"
    And I should see "Your grade: 100"
    And I should see "Start another attempt"

  Scenario: A student ends the attempt early and is graded as they stand
    Given I am on the "Mastery check" "stackmastery activity" page logged in as "student1"
    When I press "Start attempt"
    Then I should see "Question 1 of up to 5"
    When I press "End attempt now"
    Then I should see "Your mastery so far will be recorded and this attempt will end. You cannot resume it."
    When I press "End attempt now"
    Then I should see "Your attempt has ended. Your mastery so far has been recorded."
    And I should see "Complete"
    And I should see "Your grade: 0"
