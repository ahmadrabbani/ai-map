# LDA AI Verification Voiceover Assets

These scripts prepare the project overview voiceover in two flows:

- `user-flow-adam-2min-script.txt` for the applicant journey.
- `ad-epermit-flow-adam-2min-script.txt` for the AD ePermit officer journey.

The full supplied text is also split into:

- `user-flow-adam-script.txt`
- `ad-epermit-flow-adam-script.txt`
- `full-process-adam-script.txt`

## Adam Voice

The requested voice is the ElevenLabs Adam voice. The default voice ID used by
`scripts/generate_elevenlabs_adam_audio.php` is `pNInz6obpgDQGcFmaJgB`.

Generate audio after setting an API key:

```bash
ELEVENLABS_API_KEY=your_key php scripts/generate_elevenlabs_adam_audio.php demo-output/voiceover/user-flow-adam-2min-script.txt demo-output/voiceover/user-flow-adam.mp3
ELEVENLABS_API_KEY=your_key php scripts/generate_elevenlabs_adam_audio.php demo-output/voiceover/ad-epermit-flow-adam-2min-script.txt demo-output/voiceover/ad-epermit-flow-adam.mp3
```

If you export Adam audio manually from ElevenLabs, place the MP3 files at the
same output paths above and skip the generation step.

Mux a generated/exported audio file with the existing demo video:

```bash
php scripts/mux_voiceover_video.php demo-output/map-ai-verification-animated-overview-with-sound.mp4 demo-output/voiceover/user-flow-adam.mp3 demo-output/user-flow-adam-overview.mp4
php scripts/mux_voiceover_video.php demo-output/map-ai-verification-animated-overview-with-sound.mp4 demo-output/voiceover/ad-epermit-flow-adam.mp3 demo-output/ad-epermit-flow-adam-overview.mp4
```

