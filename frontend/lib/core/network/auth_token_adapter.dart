abstract class AuthTokenAdapter {
  Future<String?> getAccessToken();

  Future<Map<String, String>> authorizeHeaders() async {
    final token = await getAccessToken();
    if (token == null || token.isEmpty) {
      return const {};
    }
    return {'Authorization': 'Bearer $token'};
  }
}
