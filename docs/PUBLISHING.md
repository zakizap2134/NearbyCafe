# Review and publish the prepared integration branch

Nothing has been pushed or merged. The commits were created in an isolated workspace; the branch is **not automatically present on your Windows computer**.

The complete delivery contains:

- `source/`: repository source plus built frontend; install from here without earlier patches.
- `NearbyCafe_Integration.bundle`: the new local commits, dependent on baseline **`ac70e70e667c26d10c58056bbcaa8c7f4d28bd37`** already in GitHub history.
- `DELIVERY.txt`: exact commit IDs and package contents.

The bundle is an optional alternative for Git users. It avoids repeating deleted private conversation files in a textual patch. It is not needed to run the complete source.

## 1. Import into a separate clean clone

Extract the delivery to `C:\Downloads\NearbyCafe-Integration`. Keep your live `C:\Projects\VenueVue\rebuild` untouched while reviewing Git changes. In **PowerShell**:

```powershell
git clone https://github.com/zakizap2134/venuevue_1.git C:\Projects\NearbyCafe-Publish
Set-Location C:\Projects\NearbyCafe-Publish
git status --short
git bundle verify "C:\Downloads\NearbyCafe-Integration\NearbyCafe_Integration.bundle"
git fetch "C:\Downloads\NearbyCafe-Integration\NearbyCafe_Integration.bundle" refs/heads/integration/nearby-cafe-complete-update:refs/heads/integration/nearby-cafe-complete-update
git switch integration/nearby-cafe-complete-update
```

If `NearbyCafe-Publish` already exists, choose a new empty folder or inspect it first; do not delete uncommitted work. If bundle verification reports a missing prerequisite, fetch origin and verify the baseline exists before retrying. Do not run `git reset --hard` or force branch replacement to bypass an error.

## 2. Review and build

```powershell
git log --oneline origin/main..HEAD
git diff --stat ac70e70e667c26d10c58056bbcaa8c7f4d28bd37..HEAD
git status --short
Set-Location .\rebuild
npm ci
npm run build
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\launcher-persistence.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\launcher-orchestration.ps1
Set-Location ..
```

Read [verification](VERIFICATION.md) and [integration/privacy review](INTEGRATION.md). Do not run database integration tests against your live database. Building does not initialize a database. Git ignores the resulting frontend build; the complete release ZIP already contains it.

## 3. Push only after reviewing

```powershell
git push -u origin integration/nearby-cafe-complete-update
```

If the remote branch already exists with different commits, inspect it and choose a new branch name; do not force-push. If `main` has advanced since the documented baseline, review the differences and resolve any conflicts before merging through GitHub. No merge was prepared or performed automatically here.

## 4. Open a pull request

If GitHub CLI is installed and authenticated:

```powershell
gh pr create --repo zakizap2134/venuevue_1 --base main --head integration/nearby-cafe-complete-update --title "Integrate complete Nearby Cafe app and Windows launcher fix" --body-file docs/PR_BODY.md
```

Otherwise open [the compare page](https://github.com/zakizap2134/venuevue_1/compare/main...integration/nearby-cafe-complete-update) after pushing, select **Create pull request**, and copy `docs/PR_BODY.md` into the description. Review files and test limitations before merging. Do not paste private configuration, recovery materials or database dumps into the PR.

After an approved merge, a normal GitHub source download requires a frontend build. To distribute a build-ready release, attach the complete delivery as an explicit release asset and describe its `source/rebuild` layout. Creating that release is a separate publishing step; it has not been done for you.
