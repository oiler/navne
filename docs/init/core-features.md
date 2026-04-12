# Core Features


## Configs and Plugin settings

- What post type are we working with, default is `post`
- Should we create a new, custom taxonomy type or integrate with the existing post `tags`
- For taxonomy pages, are we inheriting the current themes tag pages or using this plugins custom theme options
- What operating mode should the plugin use: safe (default), suggest, or yolo?


## Public facing pages

If the config ask to use our custom taxonomy pages, we'll need two designs

- simple, default taxonomy page that adds no extra info, just names and shows related posts
- enhanced taxonomy page, giving sites the ability to add more meta info, like job title, description, work history, profile photo, website link, and more (in addition to linking to other posts with the same tag)


## Setup and re-indexing

On initial setup, and when needed, the plugin can read through the entire site archive (based on the assigned post type, most likely `post`) and index the posts and match names. this is obviously an expensive task and will be rarely used. We should also offer the ability to index a subsegment of posts, likely by date range

## On post save
This invokes the core functionality of matching and suggesting names to be added or created.