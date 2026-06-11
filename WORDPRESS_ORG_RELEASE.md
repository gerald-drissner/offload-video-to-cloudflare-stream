# WordPress.org release checklist

1. Confirm `voffload-cloudflare-stream/readme.txt` passes the WordPress.org readme validator.
2. Confirm the main plugin header version and `Stable tag` match.
3. Commit the same code to SVN `trunk`.
4. Create a tag from trunk, for example:

```bash
svn cp trunk tags/1.0.0
svn commit -m "Release 1.0.0"
```

5. Do not change files inside a released tag. For any correction, increment the plugin version and create a new tag.

The installable ZIP for manual testing can be built with:

```bash
bash bin/build-zip.sh
```
