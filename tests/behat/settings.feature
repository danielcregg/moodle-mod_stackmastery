@mod @mod_stackmastery
Feature: STACK Mastery administration settings
  In order to configure adaptive mastery checks
  As an administrator
  I need to reach the module's configuration page

  # Behat in CI has no Maxima, so no scenario may instantiate a STACK question. This feature is
  # backend-independent settings smoke, matching the sibling plugins.
  Scenario: An administrator can view the STACK Mastery settings page
    Given I log in as "admin"
    When I navigate to "Plugins > Activity modules > STACK Mastery" in site administration
    Then I should see "Exploration rate (epsilon)"
    And I should see "Allowed question types"
    And I should see "Auto-abandon stale attempts after"
    And I should see "Experience log retention"
    And I should see "Export experience for retraining"
