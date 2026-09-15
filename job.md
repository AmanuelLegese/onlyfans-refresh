# Test task for Engineer @FansAPI

Build a small Laravel 13 OnlyFans profile-fetch service and use it to reproduce and fix a upstream production failure (eg. OnlyFans APIs having trouble). Show us how you investigate, protect valid data and verify recovery.

---

**TL;DR:** Retrieve full profile information from onlyfans.com/madison420ivy without burning too much RAM and make it scalable with background queue workers (Horizon).

---

You have six-seven 🤷‍♂️ hours, including setup. Top candidates finish it within 2 hours.

We expect working code, evidence that you tested it and a clear explanation of your decisions. Feel free to use AI, documentation and existing packages freely. You should understand and be able to explain everything you submit, including AI-generated code.

Submit when the time is up. Explain anything unfinished, why you prioritized other work, and what remains broken.

## Setup and scope

Use Laravel 13, Laravel Scout for search, Redis and Horizon. A basic Laravel project is enough. UI can be very basic.

Keep the application small. It needs profiles, refresh jobs, a way to trigger a repeatable workload and enough logs to follow it. A dashboard, search and a full API are not required.

## The incident

The oldest waiting jobs keep getting older. Workers report completed jobs, but some profiles show stale or empty data. One busy account delays the others. There has been no application deployment since the problem started.

Reproduce the data bug with a handler that reads `likes` from the top level of a JSON response, defaults a missing value to zero and marks every response as a successful refresh. The upstream starts returning `likes` inside a `profile` object instead. Some requests also return HTTP 429 without Retry-After, so you have to retry on a randomized delay period or HTTP 500 with an empty body.

Use a profile whose last valid likes value is 120,000. A successful old-format response contains likes: 120000 and revision: 10. The changed response contains profile: { likes: 121000 } and revision: 11. For this exercise, revision is a strictly increasing upstream version for each profile. It lets you test responses arriving out of order without relying on request timing.

Record what the broken handler does for these cases. Show the failing test or reproduction before fixing it. Do not treat a completed queue job as proof that the profile was refreshed successfully.

## Required work

### 1. Protect the data

Update the integration to accept both response formats. Validate the response before changing stored data.

- A failed request or malformed response must not erase the last valid profile or count as a successful refresh.
- A missing likes field is invalid; an explicit numeric zero is valid. Reject negative or non-numeric likes values.
- Duplicate or concurrent jobs must not create duplicate profiles. A late response with an older revision must not overwrite newer accepted data.
- Keep the last attempt, last successful refresh and failure details distinguishable.
- Refresh profiles above 100,000 likes every 24 hours and all others every 72 hours. Exactly 100,000 belongs to the 72-hour group. Avoid repeatedly scheduling work that is already pending.

Add focused tests for the response-format bug, failed requests, duplicate execution and an older response arriving last. Run them and include the results.

### 2. Keep the queue moving

Handle rate limits, timeouts, temporary server errors and permanent failures differently, with a limit on retries. Explain how the worker timeout, job timeout and Redis `retry_after` setting relate to each other. How would you avoid two workers processing the same long-running attempt?

Create a repeatable workload with at least two accounts. Make one produce a burst of jobs that encounter rate limits while the other continues to get valid responses. Choose small job counts and delays that run quickly on your machine, and record them.

Demonstrate how you prevent the failing account from consuming all capacity. Show successful refreshes continuing for the other account and recovery when the rate limit clears. Replay a job after its database write to check what happens if a worker dies before acknowledging it. Explain what your local test does and does not prove about a real worker crash.

Log enough to follow an account, profile, job and attempt through a failure and recovery. Keep secrets out of logs. For the same workload before and after your change, report successful refreshes, attempts per successful refresh and the age of the oldest waiting job. Check the stored data too.

### 3. Explain the next scaling limit

Use 50 million jobs per day as a planning scenario. That is about 579 jobs per second on average; it does not tell you peak load or required capacity.

Explain what you would measure next: peak arrivals, job duration, concurrent work, retry amplification, database and Redis pressure, upstream limits and the largest accounts. Identify the likely bottleneck in your solution, the evidence you would collect and the first change you would make. A small local workload is enough for this exercise; it is not proof that the system handles 50 million jobs a day.

## What to submit

Email join@fansapi.com a download link to Firstname_Lastname.zip. Include the source, dependency lockfiles, setup instructions, local response fixtures, regression tests and a short README.

The README should explain the failure, evidence, fix, before-and-after results, remaining limits and time spent. Include a short production plan: what you would check and mitigate in the first 15 minutes, how you would roll out the fix, when you would roll back and how you would verify recovery.

In `AI.md`, briefly explain which tools you used, what you checked and any incorrect suggestion you caught. Say what you have not verified.

## How we assess it

- Data-retrieval about the profile works: 70%.
- Queue recovery, retry behaviour and account isolation: 15%.
- Observability, scaling judgment and explanation: 15%.

We review the submission, have a 30-minute call. You will reproduce one failure and walk through the fix. We will also ask how you would investigate a new symptom, and discuss an undocumented integration and a production incident you have worked on.