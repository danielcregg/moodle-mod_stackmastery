@mod @mod_stackmastery
Feature: STACK Mastery policy administration page
  In order to control which trained selection policy serves questions
  As an administrator
  I need to review the active policy and see when no candidate is pending

  # Behat in CI has no Maxima and this page is backend-independent. A fresh site has no promoted
  # or pending artifact, so the page must render the shipped policy card and the no-pending
  # guidance (with the moodledata drop path) without error.
  Scenario: An administrator sees the active shipped policy and the no-pending state
    Given I log in as "admin"
    When I navigate to "Plugins > Activity modules > STACK Mastery policy" in site administration
    Then I should see "Active policy"
    And I should see "shipped-"
    And I should see "shipped"
    And I should see "Pending policy"
    And I should see "No pending policy"
    And I should see "No previous policy to roll back to."
    And I should see "Export runs"
