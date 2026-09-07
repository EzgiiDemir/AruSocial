import 'package:flutter/material.dart';
import 'package:video_player/video_player.dart';

/// Opens only a server-approved media URL; local upload previews stay separate.
class VideoPlaybackScreen extends StatefulWidget {
  final String url;
  const VideoPlaybackScreen({super.key, required this.url});

  @override
  State<VideoPlaybackScreen> createState() => _VideoPlaybackScreenState();
}

class _VideoPlaybackScreenState extends State<VideoPlaybackScreen> {
  VideoPlayerController? _controller;
  bool _ready = false;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final previous = _controller;
    final controller = VideoPlayerController.networkUrl(Uri.parse(widget.url));
    setState(() {
      _controller = controller;
      _ready = false;
      _failed = false;
    });
    await previous?.dispose();
    try {
      await controller.initialize();
      if (!mounted || _controller != controller) return;
      setState(() => _ready = true);
    } catch (_) {
      if (mounted && _controller == controller) setState(() => _failed = true);
    }
  }

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Video')),
        body: SafeArea(
          child: Center(
            child: _failed
                ? Column(mainAxisSize: MainAxisSize.min, children: [
                    const Text('Video oynatılamadı.'),
                    TextButton(onPressed: _load, child: const Text('Tekrar dene')),
                  ])
                : !_ready
                    ? const CircularProgressIndicator()
                    : ValueListenableBuilder<VideoPlayerValue>(
                        valueListenable: _controller!,
                        builder: (context, value, _) => Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Flexible(child: AspectRatio(
                              aspectRatio: value.aspectRatio,
                              child: VideoPlayer(_controller!),
                            )),
                            VideoProgressIndicator(_controller!, allowScrubbing: true),
                            IconButton(
                              tooltip: value.isPlaying ? 'Duraklat' : 'Oynat',
                              icon: Icon(value.isPlaying ? Icons.pause : Icons.play_arrow),
                              onPressed: () async {
                                if (value.isPlaying) {
                                  await _controller!.pause();
                                } else {
                                  if (value.position >= value.duration) {
                                    await _controller!.seekTo(Duration.zero);
                                  }
                                  await _controller!.play();
                                }
                              },
                            ),
                          ],
                        ),
                      ),
          ),
        ),
      );
}
