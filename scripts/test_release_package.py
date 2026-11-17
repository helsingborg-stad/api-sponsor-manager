import json
import subprocess
import tempfile
import unittest
import zipfile
from pathlib import Path

import release_package


class ReleasePackageTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        subprocess.run(["git", "init", "-q", str(self.root)], check=True)
        self.files = {name: b"content" for name in release_package.REQUIRED}
        self.files["assets/dist/manifest.json"] = json.dumps({
            "css/api-sponsor-manager.css": "css/app.css",
            "js/api-sponsor-manager.js": "js/app.js",
        }).encode()
        self.files["assets/dist/css/app.css"] = b"css"
        self.files["assets/dist/js/app.js"] = b"js"
        for name, content in self.files.items():
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(content)
        subprocess.run(["git", "-C", str(self.root), "add", "."], check=True)
        self.archive = self.root / "archive.zip"

    def tearDown(self):
        self.temp.cleanup()

    def test_create_contains_generated_assets_and_runtime_only(self):
        (self.root / "assets/dist/css/generated.css").write_text("generated")
        (self.root / "source/php/ExampleTest.php").write_text("test")
        subprocess.run(["git", "-C", str(self.root), "add", "source/php/ExampleTest.php"], check=True)
        release_package.create(self.root, self.archive)
        with zipfile.ZipFile(self.archive) as package:
            self.assertIn("assets/dist/css/generated.css", package.namelist())
            self.assertNotIn("source/php/ExampleTest.php", package.namelist())

    def test_missing_manifest_target_fails(self):
        (self.root / "assets/dist/js/app.js").unlink()
        with self.assertRaisesRegex(ValueError, "manifest asset"):
            release_package.create(self.root, self.archive)

    def test_forbidden_and_traversal_entries_fail(self):
        for name in ("vendor/autoload.php", "assets/dist/../secret", "source/php/.env"):
            with self.subTest(name=name):
                release_package.create(self.root, self.archive)
                with zipfile.ZipFile(self.archive, "a") as package:
                    package.writestr(name, "bad")
                with self.assertRaisesRegex(ValueError, "forbidden"):
                    release_package.validate(self.archive)

    def test_symlink_input_fails(self):
        (self.root / "assets/dist/js/link").symlink_to(self.root / "assets/dist/js/app.js")
        with self.assertRaisesRegex(ValueError, "Symlink"):
            release_package.create(self.root, self.archive)

    def test_missing_runtime_file_fails(self):
        (self.root / "source/php/App.php").unlink()
        with self.assertRaisesRegex(ValueError, "missing input"):
            release_package.create(self.root, self.archive)


if __name__ == "__main__":
    unittest.main()
