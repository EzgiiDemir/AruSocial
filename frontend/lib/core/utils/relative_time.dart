/// Human-readable elapsed time from [at] to now (device local clock).
String formatRelativeTime(DateTime at) {
  final diff = DateTime.now().difference(at.toLocal());
  if (diff.isNegative || diff.inSeconds < 45) return 'şimdi';
  if (diff.inMinutes < 60) return '${diff.inMinutes} dk önce';
  if (diff.inHours < 24) return '${diff.inHours} sa önce';
  if (diff.inDays < 7) return '${diff.inDays} g önce';
  if (diff.inDays < 30) {
    final weeks = (diff.inDays / 7).floor();
    return weeks <= 1 ? '1 hf önce' : '$weeks hf önce';
  }
  final day = at.day.toString().padLeft(2, '0');
  final month = at.month.toString().padLeft(2, '0');
  return '$day.$month.${at.year}';
}
