# Code Committing and Build Verification Workflow

When executing implementation plans and finishing tasks, adhere to the following workflow:

1. **Group Similar Changes:** Similar types of code changes should be committed together in a single commit, rather than one file at a time.
2. **Descriptive Messages:** Use clear, descriptive commit messages.
3. **Verify Builds:** Always run the build (`bun run build` or equivalent) and tests at the end of the final loop segment. Fix any build or unit test issues in subsequent loops if required.
4. **Push:** Always push the code to the repository after committing successfully and verifying the build.
