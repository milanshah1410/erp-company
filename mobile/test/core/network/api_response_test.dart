import 'package:flutter_test/flutter_test.dart';
import 'package:kosha_mobile/core/errors/api_exception.dart';
import 'package:kosha_mobile/core/network/api_response.dart';

/// Guards the §29 adaptation (decision G1).
///
/// These are the exact shapes this Laravel backend returns. If a test here
/// starts failing, either the API changed or somebody "tidied" the decoder —
/// both are worth stopping for, because every screen in the app reads through
/// this file.
void main() {
  group('ApiResponse.unwrap', () {
    test('peels the data wrapper an API Resource adds', () {
      final Map<String, dynamic> body = <String, dynamic>{
        'data': <String, dynamic>{'id': 7, 'name': 'Leather Boot'},
      };

      expect(ApiResponse.unwrap(body), <String, dynamic>{
        'id': 7,
        'name': 'Leather Boot',
      });
    });

    test('passes a bare payload through — POST /login is not wrapped', () {
      final Map<String, dynamic> body = <String, dynamic>{
        'token': 'plain-text-token',
        'user': <String, dynamic>{'id': 1},
      };

      expect(ApiResponse.unwrap(body), same(body));
    });

    test('does not mistake a collection body for a wrapped object', () {
      // `data` is a List here, so unwrapping would hand back the wrong thing.
      final Map<String, dynamic> body = <String, dynamic>{
        'data': <dynamic>[
          <String, dynamic>{'id': 1},
        ],
      };

      expect(ApiResponse.unwrap(body), same(body));
    });

    test('throws a typed error rather than a cast failure on junk', () {
      expect(
        () => ApiResponse.unwrap('<html>502 Bad Gateway</html>'),
        throwsA(isA<UnexpectedException>()),
      );
    });
  });

  group('Paginated', () {
    test('reads Laravel meta', () {
      final Paginated<int> page = Paginated<int>.fromJson(
        <String, dynamic>{
          'data': <dynamic>[
            <String, dynamic>{'id': 1},
            <String, dynamic>{'id': 2},
          ],
          'links': <String, dynamic>{'next': 'http://x/api/products?page=2'},
          'meta': <String, dynamic>{
            'current_page': 1,
            'last_page': 9,
            'per_page': 2,
            'total': 18,
          },
        },
        (Map<String, dynamic> row) => row['id'] as int,
      );

      expect(page.items, <int>[1, 2]);
      expect(page.currentPage, 1);
      expect(page.lastPage, 9);
      expect(page.total, 18);
      expect(page.hasMore, isTrue);
      expect(page.nextPage, 2);
    });

    test('a body with no meta reads as one complete page', () {
      final Paginated<int> page = Paginated<int>.fromJson(
        <String, dynamic>{
          'data': <dynamic>[
            <String, dynamic>{'id': 1},
          ],
        },
        (Map<String, dynamic> row) => row['id'] as int,
      );

      expect(page.hasMore, isFalse, reason: 'must not ask for a page 2');
      expect(page.total, 1);
    });

    test('tolerates numeric meta arriving as strings', () {
      final Paginated<int> page = Paginated<int>.fromJson(
        <String, dynamic>{
          'data': <dynamic>[],
          'meta': <String, dynamic>{
            'current_page': '2',
            'last_page': '5',
            'per_page': '15',
            'total': '73',
          },
        },
        (Map<String, dynamic> row) => row['id'] as int,
      );

      expect(page.currentPage, 2);
      expect(page.lastPage, 5);
      expect(page.hasMore, isTrue);
    });

    test('merge accumulates items and takes the newer page position', () {
      Paginated<int> page(int current, List<int> ids) => Paginated<int>(
            items: ids,
            currentPage: current,
            lastPage: 3,
            perPage: 2,
            total: 6,
          );

      final Paginated<int> merged = page(1, <int>[1, 2]).merge(page(2, <int>[3, 4]));

      expect(merged.items, <int>[1, 2, 3, 4]);
      expect(merged.currentPage, 2);
      expect(merged.hasMore, isTrue);
    });
  });
}
